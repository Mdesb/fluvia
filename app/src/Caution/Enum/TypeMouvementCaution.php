<?php

declare(strict_types=1);

namespace App\Caution\Enum;

/** Type d'un mouvement du journal append-only d'une caution (`MouvementCaution`). */
enum TypeMouvementCaution: string
{
    case Consignation = 'consignation';
    case Restitution = 'restitution';
    case Retenue = 'retenue';
    case Relance = 'relance';
    case Forcage = 'forcage';
}
