<?php

declare(strict_types=1);

namespace App\Patinoire\Enum;

/** Routage du billet vers la bonne jauge (RG-PAT-02, US-PATIN-08). */
enum TypeZonePatinoire: string
{
    case Glace = 'glace';
    case Gradins = 'gradins';
}
