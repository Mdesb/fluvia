<?php

declare(strict_types=1);

namespace App\Group\Enum;

/**
 * Catégorie d'un membre du groupe. Sert la segmentation tarifaire (enfant / adulte) et le décompte
 * des accompagnateurs, transverse à tous les métiers.
 */
enum ParticipantCategory: string
{
    case Child = 'child';
    case Adult = 'adult';
    case Accompanist = 'accompanist';
    case Other = 'other';
}
