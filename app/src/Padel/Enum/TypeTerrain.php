<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Type de terrain de padel (§4.1 spec). */
enum TypeTerrain: string
{
    case Indoor = 'indoor';
    case Outdoor = 'outdoor';
}
