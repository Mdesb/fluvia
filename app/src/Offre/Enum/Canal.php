<?php

declare(strict_types=1);

namespace App\Offre\Enum;

/**
 * Canal de vente/visibilité (RG-M1-07 / RG-M1-09).
 */
enum Canal: string
{
    case Guichet = 'guichet';
    case EnLigne = 'en_ligne';
    case Borne = 'borne';
}
