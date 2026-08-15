<?php

declare(strict_types=1);

namespace App\Recouvrement\Enum;

/**
 * Paramètre de la politique de recouvrement (décision actée, ex-spec sport §4.5, généralisé au socle
 * partagé) : moment où l'accès (droit/badge) est refusé suite à un impayé. Défaut établissement =
 * `ApresRepresentationEchouee` (comportement le moins agressif).
 */
enum MomentRefusAcces: string
{
    case Apres1erEchec = 'apres_1er_echec';
    case ApresRepresentationEchouee = 'apres_representation_echouee';
    case ApresNRepresentationsEchouees = 'apres_n_representations_echouees';
}
