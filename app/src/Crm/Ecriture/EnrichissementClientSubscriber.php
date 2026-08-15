<?php

declare(strict_types=1);

namespace App\Crm\Ecriture;

use App\Crm\Entity\Client;
use App\Crm\Service\MontantUtil;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Enrichissement automatique de la fiche client à chaque vente validée (RG-M4-01, CA-3) : historique
 * et agrégats (CA cumulé, dernière visite) mis à jour **sans re-saisie**, horodatés et attribués à
 * l'utilisateur/au flux d'origine. Écoute `App\Vente\Entity\Vente` directement (onFlush, même patron
 * que `App\Audit\Doctrine\AuditWriteSubscriber`) plutôt que de modifier M2 : M4 « reçoit »
 * l'enrichissement sans coupler le domaine Vente au domaine CRM.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class EnrichissementClientSubscriber
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Vente) {
                continue;
            }
            $changeSet = $uow->getEntityChangeSet($entity);
            if (!isset($changeSet['statut'])) {
                continue;
            }
            // NB : le changeset de Doctrine porte ici les valeurs brutes de la colonne (string),
            // pas les instances d'enum — comparaison normalisée via ->value plutôt que ===.
            [$avant, $apres] = $changeSet['statut'];
            $avantValeur = $avant instanceof StatutVente ? $avant->value : $avant;
            $apresValeur = $apres instanceof StatutVente ? $apres->value : $apres;
            if ($apresValeur !== StatutVente::Validee->value || $avantValeur === StatutVente::Validee->value) {
                continue; // Seule la transition VERS « validee » déclenche l'enrichissement.
            }

            $clientId = $entity->getClient();
            if ($clientId === null) {
                continue; // Vente anonyme (US-L2-05) : rien à enrichir côté M4.
            }
            $client = $em->getRepository(Client::class)->find($clientId);
            if (!$client instanceof Client) {
                continue;
            }

            $client->setCaCumule(MontantUtil::addition($client->getCaCumule() ?? '0.00', $entity->getTotal()));
            $client->setDateDerniereVisite(new \DateTimeImmutable());
            $client->setDateMaj(new \DateTimeImmutable());
            $utilisateur = $this->security->getUser();
            $client->setMajPar($utilisateur instanceof Utilisateur ? $utilisateur->getEmail() : 'flux:vente-m2');

            $metadata = $em->getClassMetadata(Client::class);
            $uow->recomputeSingleEntityChangeSet($metadata, $client);
        }
    }
}
