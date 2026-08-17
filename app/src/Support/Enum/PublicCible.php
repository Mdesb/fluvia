<?php

declare(strict_types=1);

namespace App\Support\Enum;

/** Public visé par un `ArticleAide` (RG-SUP-04). */
enum PublicCible: string
{
    case Agent = 'agent';
    case Usager = 'usager';
    case Tous = 'tous';
}
