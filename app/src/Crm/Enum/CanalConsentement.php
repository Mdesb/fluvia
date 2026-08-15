<?php

declare(strict_types=1);

namespace App\Crm\Enum;

enum CanalConsentement: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Courrier = 'courrier';
}
