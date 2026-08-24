<?php

declare(strict_types=1);

namespace App\Social\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Service\ContexteEtablissement;
use App\Social\Entity\SocialAccount;
use App\Social\Entity\SocialPost;
use App\Social\Entity\SocialPublication;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Rédaction d'un message et création de ses publications (D14, SOC-1).
 *
 * **Le point de sécurité de ce lot.** Le message porte un établissement dérivé du serveur, mais les
 * comptes visés arrivent du client. Chacun est donc revérifié contre le périmètre actif, un par un, et
 * un compte hors périmètre fait échouer la requête en 404 — pas en 403, qui dirait « ce compte existe
 * ailleurs » et permettrait d'énumérer les comptes des autres établissements. C'est exactement la forme
 * des seize IDOR trouvés dans ce dépôt : une entité résolue depuis l'entrée client, jamais confrontée
 * au périmètre. Le fait que la résolution des IRI passe déjà par le cloisonnement ne suffit pas — un
 * contrôle qu'on ne voit pas dans le code est un contrôle qu'on supprimera sans le savoir.
 *
 * **Refuser à la rédaction plutôt qu'à l'envoi.** La longueur maximale est connue par réseau avant
 * toute tentative : la faire échouer plus tard produirait un message publié sur trois réseaux et refusé
 * sur un quatrième pour une raison que l'auteur pouvait corriger en dix secondes. Et on ne tronque
 * jamais — personne n'a le droit de raccourcir le texte de quelqu'un d'autre sans le lui dire.
 *
 * @implements ProcessorInterface<SocialPost, SocialPost>
 */
final class SocialPostProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $establishmentContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SocialPost
    {
        \assert($data instanceof SocialPost);

        $establishment = $this->establishmentContext->etablissementActif();
        if ($establishment === null) {
            throw new UnprocessableEntityHttpException('social.error.no_active_establishment');
        }
        $data->setEstablishment($establishment);

        $accounts = $this->resolveTargets($data, $establishment->getId());
        $this->assertBodyFitsEveryNetwork($data->getBody(), $accounts);

        foreach ($accounts as $account) {
            $publication = new SocialPublication();
            $publication->setAccount($account);
            $data->addPublication($publication);
            $this->em->persist($publication);
        }

        $data->setTargetAccounts([]);
        $data->recomputeStatus();
        $data->touchUpdatedAt();

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }

    /**
     * @return list<SocialAccount>
     */
    private function resolveTargets(SocialPost $data, mixed $establishmentId): array
    {
        $targets = $data->getTargetAccounts();
        if ($targets === []) {
            // Un message sans destination n'est pas un brouillon : c'est une demande de publication
            // qui ne publiera nulle part, et qui ressemblera pourtant à un succès.
            throw new UnprocessableEntityHttpException('social.error.no_target_account');
        }

        $resolved = [];
        foreach ($targets as $account) {
            if (!$account instanceof SocialAccount) {
                throw new UnprocessableEntityHttpException('social.error.invalid_target_account');
            }

            $owner = $account->getEstablishment();
            if ($owner === null || (string) $owner->getId() !== (string) $establishmentId) {
                throw new NotFoundHttpException();
            }

            if (!$account->getStatus()->canPublish() || !$account->hasAccessToken()) {
                // Viser un compte déconnecté produirait une ligne d'emblée en échec, et un message
                // « partiellement échoué » dont la cause n'est pas le réseau mais notre propre coffre.
                throw new UnprocessableEntityHttpException('social.error.account_not_publishable');
            }

            // Deux fois le même compte donnerait deux publications identiques, dont l'une violerait la
            // contrainte d'unicité — autant le dire ici plutôt qu'en 409 à l'écriture.
            $key = (string) $account->getId();
            if (isset($resolved[$key])) {
                throw new UnprocessableEntityHttpException('social.error.duplicate_target_account');
            }
            $resolved[$key] = $account;
        }

        return array_values($resolved);
    }

    /**
     * @param list<SocialAccount> $accounts
     */
    private function assertBodyFitsEveryNetwork(string $body, array $accounts): void
    {
        // mb_strlen et non strlen : les réseaux comptent des caractères, pas des octets, et un message
        // en français plein d'accents serait refusé à tort par un comptage en octets.
        $length = mb_strlen($body);
        foreach ($accounts as $account) {
            $limit = $account->getNetwork()->maxContentLength();
            if ($length > $limit) {
                throw new UnprocessableEntityHttpException('social.error.body_too_long_for_network');
            }
        }
    }
}
