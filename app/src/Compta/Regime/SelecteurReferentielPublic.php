<?php

declare(strict_types=1);

namespace App\Compta\Regime;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\QualificationEquipement;
use App\Compta\Enum\Qualification;
use App\Compta\Enum\ReferentielComptable;

/**
 * Sous-stratégie de `RegimeRegieDirecte` : choisit M4 (SPIC) ou M57 (SPA) **ligne à ligne**, selon la
 * `QualificationEquipement` de l'Espace concerné — **seul** endroit qui teste SPIC/SPA (point EXPERT
 * #1, §4.1 spec). Défaut = `ParametresRegime::qualificationParDefaut` (SPA/M57) si l'équipement n'a
 * pas de qualification déclarée.
 */
final class SelecteurReferentielPublic
{
    public function choisir(ProfilExploitant $profil, ?QualificationEquipement $qualification): ReferentielComptable
    {
        $valeur = $qualification?->getQualification() ?? $profil->getParametresRegime()->qualificationParDefaut;

        return $valeur === Qualification::Spic ? ReferentielComptable::M4Spic : ReferentielComptable::M57;
    }
}
