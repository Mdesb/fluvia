<?php

declare(strict_types=1);

namespace App\Boutique\Enum;

/** Cycle de vie d'une demande de remboursement en ligne (RG-M3-15, §6 spec-boutique.md). */
enum StatutDemandeRemboursement: string
{
    case Recue = 'recue';
    case EnCours = 'en_cours';
    case Acceptee = 'acceptee';
    case Refusee = 'refusee';
}
