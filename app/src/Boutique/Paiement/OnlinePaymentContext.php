<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

use App\Compta\Entity\ProfilExploitant;

/**
 * What a real provider needs beyond the amount (#339): the order number it will print on its
 * statements (PayFiP REFDET, Stripe product name) and the buyer's address (PayFiP MEL is mandatory,
 * the ticket is e-mailed to it), and the operator's profile (D3-B of #365): the provider account that
 * takes the money is the one of THAT profile, on initiation and on verification alike. Stubs ignore it.
 */
final class OnlinePaymentContext
{
    public function __construct(
        public readonly string $orderNumber,
        public readonly string $payerEmail,
        public readonly ProfilExploitant $profile,
    ) {
    }
}
