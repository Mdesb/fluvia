<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

/** Why a provider notification was refused before being read. The value is the only thing ever logged. */
enum NotificationRejection: string
{
    case Missing = 'missing';
    case Malformed = 'malformed';
    case Invalid = 'invalid';
    case OutOfWindow = 'out_of_window';
    case NotConfigured = 'not_configured';
}
