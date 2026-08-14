<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/** Statut de la projection locale d'un droit (§1.3) : `devalide` consomme une dévalidation M2. */
enum StatutProjectionDroit: string
{
    case Valide = 'valide';
    case Devalide = 'devalide';
}
