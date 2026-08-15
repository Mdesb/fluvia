<?php

declare(strict_types=1);

namespace App\Securite\Enum;

/**
 * Cycle de vie d'un compte utilisateur (RG-M8-01, US-L7-03).
 */
enum StatutUtilisateur: string
{
    case Invite = 'invite';
    case Actif = 'actif';
    case Suspendu = 'suspendu';
}
