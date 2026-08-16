<?php

declare(strict_types=1);

namespace App\Reporting\Enum;

/**
 * Niveau de rattachement d'un objet Reporting dans la hiérarchie Groupe › Région › Établissement
 * (§1.1 plan-reporting.md). Pilote quel triplet FK du `RattachementNiveauTrait` fait foi.
 */
enum NiveauEntite: string
{
    case Etablissement = 'etablissement';
    case Region = 'region';
    case Groupe = 'groupe';
}
