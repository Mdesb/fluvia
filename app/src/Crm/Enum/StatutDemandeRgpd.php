<?php

declare(strict_types=1);

namespace App\Crm\Enum;

/** `realisee` verrouille la demande (garde ORM, InalterabiliteCrmListener). */
enum StatutDemandeRgpd: string
{
    case Recue = 'recue';
    case EnCours = 'en_cours';
    case Realisee = 'realisee';
    case Refusee = 'refusee';
}
