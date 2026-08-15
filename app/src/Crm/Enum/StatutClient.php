<?php

declare(strict_types=1);

namespace App\Crm\Enum;

/** Cycle de vie de la fiche client (spec-crm.md §5/§6). `fusionne` renvoie vers `fusionneDans`. */
enum StatutClient: string
{
    case Actif = 'actif';
    case Inactif = 'inactif';
    case Archive = 'archive';
    case Anonymise = 'anonymise';
    case Fusionne = 'fusionne';
}
