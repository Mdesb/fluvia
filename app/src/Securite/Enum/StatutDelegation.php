<?php

declare(strict_types=1);

namespace App\Securite\Enum;

/**
 * Cycle de vie d'une délégation temporaire de droits (US-L7-07).
 */
enum StatutDelegation: string
{
    case Active = 'active';
    case Revoquee = 'revoquee';
    case Expiree = 'expiree';
}
