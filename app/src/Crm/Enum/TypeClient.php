<?php

declare(strict_types=1);

namespace App\Crm\Enum;

/** Type de fiche client (spec-crm.md §5, M4-02) : conditionne les champs d'identité requis. */
enum TypeClient: string
{
    case Physique = 'physique';
    case Morale = 'morale';
}
