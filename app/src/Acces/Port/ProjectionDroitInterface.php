<?php

declare(strict_types=1);

namespace App\Acces\Port;

use App\Acces\Entity\DroitAcces;
use App\Organisation\Entity\Etablissement;
use Symfony\Component\Uid\Uuid;

/**
 * Port de projection locale d'un droit vendu M1/M2 (point ouvert n°9 du plan) : la source de vérité
 * reste M1/M2 ; L3 en fait une copie de travail (cache fenêtre/crédit/marges) pour valider < 1 s y
 * compris hors-ligne. Implémenté en L3 par un stub ; un consommateur d'événements M1/M2 réel est
 * prévu à l'intégration L4.
 */
interface ProjectionDroitInterface
{
    /** Projette (crée ou met à jour) la projection locale du droit rattaché à un support vendu M2. */
    public function projeter(Uuid $billetSupportRef, Etablissement $etablissement): DroitAcces;
}
