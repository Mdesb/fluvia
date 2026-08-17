<?php

declare(strict_types=1);

namespace App\Support\Enum;

/** Statut d'un `ArticleAide` (RG-SUP-02). */
enum StatutArticle: string
{
    case Brouillon = 'brouillon';
    case Publie = 'publie';
    case Archive = 'archive';
}
