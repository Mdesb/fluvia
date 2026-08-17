<?php

declare(strict_types=1);

namespace App\Support\Enum;

/** Résultat d'une ligne de `JournalImportAide` (RG-SUP-08). */
enum ResultatImport: string
{
    case Cree = 'cree';
    case Maj = 'maj';
    case Inchange = 'inchange';
    case Erreur = 'erreur';
}
