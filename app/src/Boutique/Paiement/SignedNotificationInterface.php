<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

use Symfony\Component\HttpFoundation\Request;

/**
 * Optional capability of a provider whose server-to-server notification is signed (Stripe). The
 * notification controller checks it BEFORE reading anything from the request or touching the database.
 * A provider that does not sign (PayFiP) simply does not implement it.
 */
interface SignedNotificationInterface
{
    /** @throws InvalidNotificationSignatureException when the notification is not authentic and recent */
    public function verifyNotification(Request $request): void;
}
