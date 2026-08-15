<?php

declare(strict_types=1);

namespace App\Crm\Enum;

/** État d'un consentement RGPD (RG-M4-07). `a_renouveler` = passage à la majorité (US-L5-10). */
enum EtatConsentement: string
{
    case Accorde = 'accorde';
    case Refuse = 'refuse';
    case ARenouveler = 'a_renouveler';
    case Expire = 'expire';
}
