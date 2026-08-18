<?php

declare(strict_types=1);

namespace App\Autorisation\Enum;

/** Cycle de vie d'une `DemandeEscalade` (RG-AUTZ-06/07). */
enum StatutEscalade: string
{
    case EnAttente = 'en_attente';
    case Approuvee = 'approuvee';
    case Rejetee = 'rejetee';
    case Expiree = 'expiree';
}
