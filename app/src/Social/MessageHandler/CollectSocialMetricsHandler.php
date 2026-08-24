<?php

declare(strict_types=1);

namespace App\Social\MessageHandler;

use App\Social\Crypto\SocialTokenCipher;
use App\Social\Dto\MetricsRequest;
use App\Social\Entity\SocialMetricSnapshot;
use App\Social\Entity\SocialPublication;
use App\Social\Enum\SocialPublicationStatus;
use App\Social\Exception\SocialPublishingException;
use App\Social\Exception\SocialTokenCipherException;
use App\Social\Message\CollectSocialMetrics;
use App\Social\Service\SocialMetricsCollectorRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Prend un instantané des statistiques d'une publication (SOC-3, D14 contrainte 2).
 *
 * Même discipline que le handler de publication, pour les mêmes raisons : l'identifiant vient d'un
 * message, donc d'une entrée non fiable ; il n'y a aucune session dont dériver un périmètre ; le
 * contexte est rétabli depuis l'entité résolue et l'invariant vérifié — le compte appartient au même
 * établissement que le message.
 *
 * **Une différence essentielle avec la publication : ici, échouer n'est pas grave.** On réessaiera au
 * prochain passage. C'est pourquoi une erreur définitive n'est pas consignée comme un incident sur la
 * publication, sauf lorsqu'elle signifie qu'il n'y aura plus jamais rien à collecter — un statut
 * supprimé chez le réseau. Dans ce seul cas on pose une borne, sans quoi la collecte planifiée
 * redemanderait éternellement un statut disparu et consommerait le quota de l'établissement.
 *
 * **On n'écrase jamais un relevé précédent.** Chaque passage ajoute une ligne : c'est la courbe qu'on
 * cherche, pas la valeur du jour, et les plateformes ne la rendront pas rétroactivement.
 */
#[AsMessageHandler]
final class CollectSocialMetricsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialMetricsCollectorRegistry $registry,
        private readonly SocialTokenCipher $cipher,
    ) {
    }

    public function __invoke(CollectSocialMetrics $message): void
    {
        if (!Uuid::isValid($message->publicationId)) {
            return;
        }

        $publication = $this->em->getRepository(SocialPublication::class)->find(Uuid::fromString($message->publicationId));
        if ($publication === null || $publication->getMetricsStoppedAt() !== null) {
            return;
        }
        if ($publication->getStatus() !== SocialPublicationStatus::Published) {
            // Rien n'est paru : il n'y a rien à mesurer, et ce n'est pas une anomalie.
            return;
        }

        $remotePostId = $publication->getRemotePostId();
        $post = $publication->getPost();
        $account = $publication->getAccount();
        if ($remotePostId === null || $remotePostId === '' || $post === null || $account === null) {
            return;
        }

        $accountEstablishment = $account->getEstablishment()?->getId();
        $postEstablishment = $post->getEstablishment()?->getId();
        if ($accountEstablishment === null || $postEstablishment === null || (string) $accountEstablishment !== (string) $postEstablishment) {
            // Même invariant que pour la publication : hors HTTP, c'est le seul contrôle possible, et
            // son absence ferait de la file un chemin de contournement du cloisonnement.
            $publication->stopMetrics('scope_violation');
            $this->em->flush();

            return;
        }

        if (!$account->hasAccessToken() || !$this->registry->supports($account->getNetwork())) {
            // Un compte déconnecté, ou un réseau qui sait publier mais pas encore mesurer : on ne
            // demande pas. Ce n'est pas une panne, et cela redeviendra possible.
            return;
        }

        try {
            $token = $this->cipher->decrypt((string) $account->getAccessTokenEncrypted());
        } catch (SocialTokenCipherException) {
            return;
        }

        try {
            $metrics = $this->registry->for($account->getNetwork())->collect(new MetricsRequest(
                network: $account->getNetwork(),
                host: $account->getHost(),
                accessToken: $token,
                handle: $account->getHandle(),
                remotePostId: $remotePostId,
            ));
        } catch (SocialPublishingException $e) {
            if ($e->errorCode === 'remote_post_gone') {
                $publication->stopMetrics('remote_post_gone');
                $this->em->flush();

                return;
            }

            if ($e->retryable) {
                throw new RecoverableMessageHandlingException($e->getMessage(), previous: $e);
            }

            // Refus définitif mais pas irrémédiable (jeton à reconnecter, par exemple) : on ne pose pas
            // de borne, le prochain passage réessaiera une fois le compte rétabli.
            return;
        }

        $snapshot = new SocialMetricSnapshot();
        $snapshot->setPublication($publication)
            ->setLikes($metrics->likes)
            ->setShares($metrics->shares)
            ->setReplies($metrics->replies)
            ->setImpressions($metrics->impressions)
            ->setRawPayload($metrics->raw);

        $this->em->persist($snapshot);
        $this->em->flush();
    }
}
