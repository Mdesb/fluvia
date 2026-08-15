<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/**
 * Paramètre de la politique anti-impayés (décision actée, spec §4.5) : moment où le badge est refusé.
 * Défaut établissement = `ApresRepresentationEchouee` (comportement le moins agressif, ⚠ à confirmer).
 */
enum MomentRefusBadge: string
{
    case Apres1erEchec = 'apres_1er_echec';
    case ApresRepresentationEchouee = 'apres_representation_echouee';
    case ApresNRepresentationsEchouees = 'apres_n_representations_echouees';
}
