<?php

declare(strict_types=1);

namespace App\PublicApi\Enum;

/** L'etat d'une cle. Une cle revoquee ne redevient jamais active : on en frappe une neuve. */
enum CredentialStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
