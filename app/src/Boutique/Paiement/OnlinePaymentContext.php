<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

/**
 * What a real provider needs beyond the amount (#339): the order number it will print on its
 * statements (PayFiP REFDET, Stripe product name) and the buyer's address (PayFiP MEL is mandatory,
 * the ticket is e-mailed to it). Stubs ignore it.
 */
final class OnlinePaymentContext
{
    public function __construct(
        public readonly string $orderNumber,
        public readonly string $payerEmail,
    ) {
    }
}
