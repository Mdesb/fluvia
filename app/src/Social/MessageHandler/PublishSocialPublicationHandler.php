<?php

declare(strict_types=1);

namespace App\Social\MessageHandler;

use App\Social\Crypto\SocialTokenCipher;
use App\Social\Dto\PublicationRequest;
use App\Social\Entity\SocialAccount;
use App\Social\Entity\SocialPublication;
use App\Social\Enum\SocialAccountStatus;
use App\Social\Enum\SocialPublicationStatus;
use App\Social\Exception\SocialPublishingException;
use App\Social\Exception\SocialTokenCipherException;
use App\Social\Message\PublishSocialPublication;
use App\Social\Service\SocialPublisherRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Envoie une publication vers son réseau (SOC-2, D7-bis).
 *
 * ## Le point de sécurité : l'identifiant vient d'un message, pas d'une session
 *
 * Un identifiant qui arrive dans un message asynchrone est une **entrée non fiable au même titre qu'un
 * corps de requête HTTP**. Il n'y a ici aucun utilisateur, donc aucun périmètre à dériver d'une
 * session : le handler rétablit le contexte depuis l'entité résolue et vérifie l'invariant qui doit
 * tenir — le compte visé appartient au même établissement que le message. Si l'invariant est faux, on
 * ne publie pas. C'est le seul contrôle possible hors HTTP, et son absence transformerait la file en
 * chemin de contournement du cloisonnement.
 *
 * ## Rejouer un message ne doit jamais republier
 *
 * Une file redélivre : c'est sa nature, pas une anomalie. Une publication déjà terminale est donc
 * ignorée en silence. Sans cela, un redémarrage du worker ferait paraître deux fois le même message
 * sur le fil d'un client.
 *
 * ## L'état terminal ne dépend pas de la configuration de la file
 *
 * Le nombre de tentatives est compté **dans la donnée**, pas déduit du transport. Si l'on se reposait
 * sur les reprises de `messenger.yaml`, une publication épuisée finirait dans le transport d'échec en
 * restant « en attente » pour toujours dans notre base — visible nulle part, corrigeable par personne.
 * Un jour où l'on ajusterait `max_retries`, on changerait silencieusement la sémantique de
 * l'historique. On tranche donc ici.
 */
#[AsMessageHandler]
final class PublishSocialPublicationHandler
{
    /**
     * Doit rester cohérent avec `max_retries` de `messenger.yaml` (5 reprises + la tentative
     * initiale). Si les deux divergent, c'est celui-ci qui gagne — et c'est voulu : mieux vaut une
     * publication marquée en échec un peu tôt qu'une publication en attente éternelle.
     */
    private const MAX_ATTEMPTS = 6;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialPublisherRegistry $registry,
        private readonly SocialTokenCipher $cipher,
    ) {
    }

    public function __invoke(PublishSocialPublication $message): void
    {
        if (!Uuid::isValid($message->publicationId)) {
            return;
        }

        $publication = $this->em->getRepository(SocialPublication::class)->find(Uuid::fromString($message->publicationId));
        if ($publication === null) {
            // La publication a disparu : rien à envoyer, et rejouer n'y changera rien.
            return;
        }
        if ($publication->getStatus()->isTerminal()) {
            return;
        }

        $post = $publication->getPost();
        $account = $publication->getAccount();
        if ($post === null || $account === null) {
            $this->finish($publication, SocialPublicationStatus::Failed, 'inconsistent_publication', 'Publication sans message ou sans compte.');

            return;
        }

        $accountEstablishment = $account->getEstablishment()?->getId();
        $postEstablishment = $post->getEstablishment()?->getId();
        if ($accountEstablishment === null || $postEstablishment === null || (string) $accountEstablishment !== (string) $postEstablishment) {
            // Invariant rompu : on refuse de publier plutôt que de publier au nom d'un autre.
            $this->finish($publication, SocialPublicationStatus::Failed, 'scope_violation', 'Le compte visé n appartient pas à l établissement du message.');

            return;
        }

        if (!$account->getStatus()->canPublish() || !$account->hasAccessToken()) {
            // Non tentée délibérément : rien n'a été demandé au réseau. `Skipped` et non `Failed`,
            // pour ne pas faire chercher une panne réseau là où le compte n'était plus connecté.
            $this->finish($publication, SocialPublicationStatus::Skipped, 'account_not_connected', 'Compte révoqué ou sans jeton au moment de l envoi.');

            return;
        }

        if (!$this->registry->supports($account->getNetwork())) {
            $this->finish($publication, SocialPublicationStatus::Failed, 'no_adapter', 'Aucun adaptateur pour ce réseau.');

            return;
        }

        try {
            $token = $this->cipher->decrypt((string) $account->getAccessTokenEncrypted());
        } catch (SocialTokenCipherException $e) {
            // Jeton illisible : version de clé retirée trop tôt, ou valeur corrompue. Réessayer ne
            // changera rien, et le motif doit être distinct d'un refus du réseau pour qu'on cherche au
            // bon endroit.
            $this->finish($publication, SocialPublicationStatus::Failed, 'token_unreadable', $e->getMessage());

            return;
        }

        $publication->setAttempts($publication->getAttempts() + 1)->setStatus(SocialPublicationStatus::Publishing);
        $post->recomputeStatus();
        $this->em->flush();

        try {
            $outcome = $this->registry->for($account->getNetwork())->publish(new PublicationRequest(
                network: $account->getNetwork(),
                host: $account->getHost(),
                accessToken: $token,
                remoteAccountId: $account->getRemoteAccountId(),
                handle: $account->getHandle(),
                body: $post->getBody(),
                // Stable d'une tentative à l'autre : c'est ce qui permet à un réseau qui sait
                // dédoublonner de ne pas republier après une coupure.
                idempotencyKey: (string) $publication->getId(),
            ));
        } catch (SocialPublishingException $e) {
            $this->handleFailure($publication, $account, $e);

            return;
        }

        $publication->setRemotePostId($outcome->remotePostId)
            ->setRemoteUrl($outcome->remoteUrl)
            ->setErrorCode(null)
            ->setErrorMessage(null)
            ->setPublishedAt(new \DateTimeImmutable());
        $this->finish($publication, SocialPublicationStatus::Published, null, null);
    }

    private function handleFailure(SocialPublication $publication, SocialAccount $account, SocialPublishingException $e): void
    {
        if ($e->errorCode === 'unauthorized') {
            // Le réseau fait foi sur l'état du jeton, pas notre horloge : c'est son refus qui bascule
            // le compte, et c'est ce qui permettra à l'interface de proposer « reconnecter ».
            $account->setStatus(SocialAccountStatus::TokenExpired);
        }

        if (!$e->retryable || $publication->getAttempts() >= self::MAX_ATTEMPTS) {
            $this->finish($publication, SocialPublicationStatus::Failed, $e->errorCode, $e->getMessage());

            return;
        }

        // Repassée en attente et non laissée « en cours » : si le worker meurt avant la reprise,
        // l'état en base doit dire la vérité, c'est-à-dire qu'il reste quelque chose à faire.
        $publication->setStatus(SocialPublicationStatus::Pending)
            ->setErrorCode($e->errorCode)
            ->setErrorMessage($e->getMessage());
        $publication->getPost()?->recomputeStatus();
        $this->em->flush();

        throw new RecoverableMessageHandlingException($e->getMessage(), previous: $e);
    }

    private function finish(SocialPublication $publication, SocialPublicationStatus $status, ?string $errorCode, ?string $errorMessage): void
    {
        $publication->setStatus($status);
        if ($errorCode !== null) {
            $publication->setErrorCode($errorCode)->setErrorMessage($errorMessage);
        }
        $publication->getPost()?->recomputeStatus()->touchUpdatedAt();
        $this->em->flush();
    }
}
