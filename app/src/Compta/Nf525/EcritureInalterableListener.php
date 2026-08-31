<?php

declare(strict_types=1);

namespace App\Compta\Nf525;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\LigneEcriture;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Garde d'inaltérabilité des écritures comptables (US-L4-09, CA-7/CA-13) : une écriture scellée
 * (empreinte non vide) n'est plus modifiable ni supprimable dans son **contenu financier**, ni ses
 * lignes. Toute correction du contenu passe exclusivement par une écriture d'extourne (§4.2 spec).
 * Seule exception : la transition de `statut` (cycle de vie provisoire→contrôlée→validée→exportée,
 * §4.10 spec) reste possible après scellement — le scellement fige les montants/comptes/taux, pas le
 * statut de validation. Même pattern que `App\Vente\Nf525\InalterabiliteListener` (M2), dupliqué ici
 * volontairement pour ne pas coupler le domaine Compta à M2.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class EcritureInalterableListener
{
    /** Champs dont la modification reste autorisée après scellement (cycle de vie, pas le contenu). */
    private const CHAMPS_AUTORISES_APRES_SCELLEMENT = ['statut'];

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof EcritureComptable && $this->modificationInterditeApresScellement($args)) {
            throw new ConflictHttpException(
                'Modification interdite : écriture comptable scellée (NF525, inaltérable). Corriger par extourne.'
            );
        }

        if ($entity instanceof LigneEcriture && ($entity->getEcriture()?->estScellee() ?? false)) {
            throw new ConflictHttpException('Modification interdite : ligne d\'une écriture scellée (NF525).');
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof EcritureComptable && $entity->estScellee()) {
            throw new ConflictHttpException('Suppression interdite : écriture comptable scellée (NF525).');
        }

        if ($entity instanceof LigneEcriture && ($entity->getEcriture()?->estScellee() ?? false)) {
            throw new ConflictHttpException('Suppression interdite : ligne d\'une écriture scellée (NF525).');
        }
    }

    private function modificationInterditeApresScellement(PreUpdateEventArgs $args): bool
    {
        $entity = $args->getObject();
        \assert($entity instanceof EcritureComptable);

        $changeSet = $args->getEntityChangeSet();
        // Le scellement lui-même (empreinte '' -> valeur) doit rester possible ; toute autre
        // modification d'une écriture déjà scellée (empreinte déjà non vide avant ce flush) est
        // interdite, SAUF la transition de statut (cycle de vie, CA-14).
        $empreinteOrigine = isset($changeSet['empreinte']) ? $changeSet['empreinte'][0] : $entity->getEmpreinte();
        if (!\is_string($empreinteOrigine) || $empreinteOrigine === '') {
            return false; // Pas encore scellée : scellement initial autorisé.
        }

        $champsModifies = array_keys($changeSet);

        // ⚠ `payloadCanonique` N'EST NI DU SUIVI NI DU CONTENU, ET LA LISTE BLANCHE NE SAIT PAS
        // L'EXPRIMER.
        //
        // Il n'est pas du contenu : il ENREGISTRE le contenu tel qu'il a été scellé. Mais l'ajouter
        // simplement à la liste ci-dessus laisserait RÉÉCRIRE un instantané existant — donc changer
        // après coup ce qu'une écriture est censée avoir été. Ce serait ouvrir précisément la porte
        // que ce garde ferme.
        //
        // La règle est donc plus étroite que la liste blanche, qui raisonne par nom de champ :
        //
        //     null   → valeur    autorisé UNE FOIS, on enregistre ce qui manquait
        //     valeur → autre     REFUSÉ, c'est le seul cas où ce champ pourrait mentir
        //
        // La condition qui décide si l'écriture est légitime — l'empreinte se vérifie-t-elle encore ?
        // — vit dans `reprendreInstantane()`. Ici on ne garantit que l'irréversibilité.
        if (isset($changeSet['payloadCanonique'])) {
            if ($changeSet['payloadCanonique'][0] !== null) {
                return true;
            }

            $champsModifies = array_values(array_diff($champsModifies, ['payloadCanonique']));
        }

        $champsNonAutorises = array_diff($champsModifies, self::CHAMPS_AUTORISES_APRES_SCELLEMENT);

        return $champsNonAutorises !== [];
    }
}
