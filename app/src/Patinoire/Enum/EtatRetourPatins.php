<?php

declare(strict_types=1);

namespace App\Patinoire\Enum;

/** État constaté au retour d'une paire de patins (RG-PAT-05, US-PATIN-03). */
enum EtatRetourPatins: string
{
    case Bon = 'bon';
    case Casse = 'casse';
    case NonRendu = 'non_rendu';
}
