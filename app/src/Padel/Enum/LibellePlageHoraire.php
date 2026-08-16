<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Libellé d'une plage horaire de tarification (RG-PADEL-02). */
enum LibellePlageHoraire: string
{
    case Pleine = 'pleine';
    case Creuse = 'creuse';
}
