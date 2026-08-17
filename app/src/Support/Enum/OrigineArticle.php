<?php

declare(strict_types=1);

namespace App\Support\Enum;

/** Origine d'un `ArticleAide`/`VersionArticle` : rédaction manuelle ou import doc vivante (RG-SUP-08). */
enum OrigineArticle: string
{
    case Manuel = 'manuel';
    case Import = 'import';
}
