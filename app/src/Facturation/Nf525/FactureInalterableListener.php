<?php

declare(strict_types=1);

namespace App\Facturation\Nf525;

use App\Facturation\Entity\Facture;
use App\Facturation\Entity\LigneFacture;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Garde d'inaltérabilité des documents commerciaux (RG-FACT-01/04/05, constitution §4.5 NF525).
 *
 * **Un document émis est immuable** : dès que l'empreinte de chaînage est posée, toute modification
 * de son contenu — lignes, montants, destinataire, numéro, dates d'émission — est refusée par un
 * **409 Conflict**. Un document émis ne s'annule pas non plus : il ne peut être ni supprimé, ni
 * remis en brouillon ; la seule correction possible est l'émission d'un **avoir** qui le référence.
 *
 * Restent autorisés après scellement, car ils ne touchent pas au contenu du document mais à son
 * **suivi** : la progression du statut (émise → acquittée/en attente de paiement → partiellement
 * réglée → payée → échue), et le rattachement des objets de suivi produits *après* l'émission
 * (écriture comptable et ligne de lettrage générées dans la même transaction, dépôt Chorus Pro
 * rejouable sans nouveau numéro — cas limite « échec de dépôt Chorus Pro » de la spec §7).
 *
 * Même patron que `App\Compta\Nf525\EcritureInalterableListener` (M6) et
 * `App\Vente\Nf525\InalterabiliteListener` (M2), volontairement dupliqué pour ne pas coupler les
 * domaines.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class FactureInalterableListener
{
    /** Champs de **suivi** (jamais de contenu) modifiables après scellement. */
    private const CHAMPS_AUTORISES_APRES_SCELLEMENT = [
        'statut',
        'canal',
        'ecritureGeneree',
        'ligneEcritureClient',
        'factureB2G',
        'mentionAcquittee',
        'acquitteeLe',
        'acquitteeMoyen',
        'acquitteeReference',
    ];

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entite = $args->getObject();

        if ($entite instanceof Facture && $this->modificationInterdite($args, $entite)) {
            throw new ConflictHttpException(
                "Modification interdite : document déjà émis, donc inaltérable (NF525). La seule correction possible est l'émission d'un avoir."
            );
        }

        if ($entite instanceof LigneFacture && ($entite->getFacture()?->estScellee() ?? false)) {
            throw new ConflictHttpException("Modification interdite : ligne d'un document déjà émis (NF525).");
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entite = $args->getObject();

        if ($entite instanceof Facture && $entite->estScellee()) {
            throw new ConflictHttpException(
                "Suppression interdite : un document émis ne s'annule jamais, il se corrige par un avoir (NF525)."
            );
        }

        if ($entite instanceof LigneFacture && ($entite->getFacture()?->estScellee() ?? false)) {
            throw new ConflictHttpException("Suppression interdite : ligne d'un document déjà émis (NF525).");
        }
    }

    private function modificationInterdite(PreUpdateEventArgs $args, Facture $facture): bool
    {
        $changeSet = $args->getEntityChangeSet();

        // Le scellement lui-même ('' -> empreinte) doit rester possible : on regarde la valeur
        // AVANT ce flush. Si le document n'était pas encore scellé, on laisse passer.
        $empreinteOrigine = isset($changeSet['empreinte']) ? $changeSet['empreinte'][0] : $facture->getEmpreinte();
        if (!\is_string($empreinteOrigine) || $empreinteOrigine === '') {
            return false;
        }

        $champsModifies = array_keys($changeSet);

        // ⚠ `payloadCanonique` N'EST NI DU SUIVI NI DU CONTENU, ET LA LISTE BLANCHE NE SAIT PAS
        // L'EXPRIMER.
        //
        // Il n'est pas du contenu : il ENREGISTRE le contenu tel qu'il a été scellé. Mais l'ajouter
        // simplement à la liste ci-dessus laisserait RÉÉCRIRE un instantané existant — c'est-à-dire
        // changer après coup ce qu'un document est censé avoir été. Ce serait ouvrir précisément la
        // porte que ce garde ferme.
        //
        // La règle est donc plus étroite que la liste blanche, qui raisonne par nom de champ :
        //
        //     null   → valeur    autorisé UNE FOIS, on enregistre ce qui manquait
        //     valeur → autre     REFUSÉ, c'est le seul cas où ce champ pourrait mentir
        //
        // Écrire l'instantané d'un document déjà scellé n'est permis que si son empreinte se vérifie
        // encore — la coïncidence étant alors la preuve qu'il est bien d'origine. Cette condition-là
        // vit dans `reprendreInstantane()` ; ici on garantit seulement l'irréversibilité.
        if (isset($changeSet['payloadCanonique'])) {
            if ($changeSet['payloadCanonique'][0] !== null) {
                return true;
            }

            $champsModifies = array_values(array_diff($champsModifies, ['payloadCanonique']));
        }

        return array_diff($champsModifies, self::CHAMPS_AUTORISES_APRES_SCELLEMENT) !== [];
    }
}
