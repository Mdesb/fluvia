<?php

declare(strict_types=1);

namespace App\Vente\Nf525;

use App\Caisse\Entity\ClotureZ;
use App\Vente\Entity\Avoir;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Paiement;
use App\Vente\Entity\SettlementCorrection;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use App\Vente\Nf525\Entity\DailyClosure;
use App\Vente\Nf525\Entity\OperationScellee;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Garde d'inaltérabilité NF525 (US-L2-11 / CA-15) : interdit toute modification/suppression au niveau
 * ORM d'une opération scellée ou d'une vente validée. Les entités append-only (OperationScellee,
 * Avoir, ClotureZ, Paiement) ne sont jamais modifiables ni supprimables. Une vente validée n'accepte
 * que ses transitions d'état (annulation) et l'indicateur d'impression ; ses lignes deviennent figées.
 * Toute correction passe par contre-passation.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class InalterabiliteListener
{
    /** Champs d'une vente scellée dont la modification est interdite (le contenu fiscal est figé). */
    private const CHAMPS_VENTE_FIGES = [
        'numero', 'total', 'totalRemises', 'session', 'cleIdempotence',
        'client', 'date', 'origineHorsLigne', 'etablissement',
        // D44-bis — **ajouté après coup, et c'est un défaut que j'avais introduit.** `pointDeVente`
        // est devenu l'ancre fiscale de la vente : la chaîne d'empreintes est chaînée par point de
        // vente, et l'arrêté de journée totalise par point de vente. Il était jusque-là protégé **par
        // ricochet**, puisqu'il se déduisait de `session`, déjà figée. En le portant sur la vente,
        // j'ai coupé le ricochet sans remplacer la protection — et rien ne s'est rompu pour le dire.
        //
        // Sans cette ligne, déplacer une vente scellée d'une chaîne à l'autre la ferait disparaître
        // d'un arrêté de totaux et apparaître dans un autre, en silence.
        'pointDeVente',
    ];

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($this->estAppendOnly($entity)) {
            throw new OperationInalterableException(sprintf(
                'Modification interdite : %s est scellé (NF525, inaltérable). Corriger par contre-passation.',
                $this->nom($entity),
            ));
        }

        if ($entity instanceof Vente) {
            $changeSet = $args->getEntityChangeSet();
            $statutOrigine = isset($changeSet['statut']) ? $changeSet['statut'][0] : $entity->getStatut();
            if ($statutOrigine instanceof StatutVente && $statutOrigine->estScellee()) {
                foreach (self::CHAMPS_VENTE_FIGES as $champ) {
                    if (isset($changeSet[$champ])) {
                        throw new OperationInalterableException(sprintf(
                            'Modification interdite : la vente est validée (NF525) ; champ « %s » figé.',
                            $champ,
                        ));
                    }
                }
            }
        }

        if ($entity instanceof LigneVente && $this->venteScellee($entity)) {
            throw new OperationInalterableException(
                'Modification interdite : la ligne appartient à une vente validée (NF525).',
            );
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($this->estAppendOnly($entity)) {
            throw new OperationInalterableException(sprintf(
                'Suppression interdite : %s est scellé (NF525, append-only). Corriger par contre-passation.',
                $this->nom($entity),
            ));
        }

        if ($entity instanceof Vente && $entity->estScellee()) {
            throw new OperationInalterableException('Suppression interdite : la vente est validée (NF525).');
        }

        if ($entity instanceof LigneVente && $this->venteScellee($entity)) {
            throw new OperationInalterableException('Suppression interdite : ligne d\'une vente validée (NF525).');
        }
    }

    private function estAppendOnly(object $entity): bool
    {
        return $entity instanceof OperationScellee
            || $entity instanceof Avoir
            || $entity instanceof ClotureZ
            || $entity instanceof Paiement
            // D57 — l'arrêté de journée. Une clôture qu'on pourrait rejouer ou effacer ne prouverait
            // rien : c'est le cumul perpétuel qu'elle porte qui rend une suppression détectable, et un
            // cumul qu'on peut réécrire ne détecte plus que ce qu'on veut bien lui laisser voir.
            || $entity instanceof DailyClosure
            // D45 — **oubli de mon propre lot, relevé en écrivant celui-ci.** La correction de
            // ventilation est scellée dans la chaîne au même titre qu'un avoir, et elle n'était
            // protégée par rien : elle est restée modifiable et supprimable pendant une journée.
            || $entity instanceof SettlementCorrection;
    }

    private function venteScellee(LigneVente $ligne): bool
    {
        $vente = $ligne->getVente();

        return $vente !== null && $vente->estScellee();
    }

    private function nom(object $entity): string
    {
        return (new \ReflectionClass($entity))->getShortName();
    }
}
