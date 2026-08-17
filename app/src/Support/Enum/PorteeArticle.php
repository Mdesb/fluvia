<?php

declare(strict_types=1);

namespace App\Support\Enum;

/** Portée d'un `ArticleAide` : global (éditeur/production) ou local à un établissement (RG-SUP-04). */
enum PorteeArticle: string
{
    case Global = 'global';
    case Local = 'local';
}
