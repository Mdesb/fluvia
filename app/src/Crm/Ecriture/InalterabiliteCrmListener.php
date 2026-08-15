<?php

declare(strict_types=1);

namespace App\Crm\Ecriture;

use App\Crm\Entity\Consentement;
use App\Crm\Entity\DemandeRGPD;
use App\Crm\Entity\JournalFusion;
use App\Crm\Entity\MouvementPmv;
use App\Crm\Enum\StatutDemandeRgpd;
use App\Crm\Enum\StatutJournalFusion;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Garde d'inaltérabilité M4 (§3.4 plan-crm.md), même patron que
 * `App\Vente\Nf525\InalterabiliteListener` / `App\Compta\Nf525\EcritureInalterableListener` :
 * - `MouvementPmv` / `Consentement` : **append-only**, jamais modifiés ni supprimés.
 * - `DemandeRGPD` : verrouillée une fois `realisee`.
 * - `JournalFusion` : append-only sauf transition de défusion (`statut`, `dateDefusion`, `defusionnePar`).
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class InalterabiliteCrmListener
{
    /** @var list<string> Seuls champs modifiables sur un JournalFusion actif (transition de défusion). */
    private const CHAMPS_DEFUSION = ['statut', 'dateDefusion', 'defusionnePar'];

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof MouvementPmv) {
            throw new ConflictHttpException('MouvementPmv est append-only : modification interdite (RG-SOCLE-07).');
        }

        if ($entity instanceof Consentement) {
            throw new ConflictHttpException('Consentement est append-only : modification interdite (RG-M4-07).');
        }

        if ($entity instanceof DemandeRGPD) {
            $changeSet = $args->getEntityChangeSet();
            $statutAvant = isset($changeSet['statut']) ? $changeSet['statut'][0] : $entity->getStatut();
            if ($statutAvant === StatutDemandeRgpd::Realisee) {
                throw new ConflictHttpException('Demande RGPD déjà réalisée : verrouillée (immuable).');
            }
        }

        if ($entity instanceof JournalFusion) {
            $changeSet = $args->getEntityChangeSet();
            $champsNonAutorises = array_diff(array_keys($changeSet), self::CHAMPS_DEFUSION);
            if ($champsNonAutorises !== []) {
                throw new ConflictHttpException('JournalFusion est append-only : seule la transition de défusion est modifiable.');
            }
            $statutAvant = isset($changeSet['statut']) ? $changeSet['statut'][0] : $entity->getStatut();
            if ($statutAvant === StatutJournalFusion::Defusionnee) {
                throw new ConflictHttpException('Fusion déjà défaite : verrouillée.');
            }
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof MouvementPmv || $entity instanceof Consentement || $entity instanceof JournalFusion) {
            throw new ConflictHttpException('Suppression interdite : entrée append-only (RG-SOCLE-07).');
        }

        if ($entity instanceof DemandeRGPD && $entity->getStatut() === StatutDemandeRgpd::Realisee) {
            throw new ConflictHttpException('Suppression interdite : demande RGPD réalisée (verrouillée).');
        }
    }
}
