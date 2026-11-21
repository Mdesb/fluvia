<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

use App\Compta\Entity\ProfilExploitant;
use Symfony\Component\HttpFoundation\Request;

/**
 * Optional capability of a provider whose server-to-server notification is signed (Stripe). The
 * notification controller checks it BEFORE reading anything from the request or touching the database.
 * A provider that does not sign (PayFiP) simply does not implement it.
 */
interface SignedNotificationInterface
{
    /**
     * `$profileId`: the operator named by the notification address (`…/notification/stripe/{profileId}`),
     * `null` on the global address. The only thing read before the signature is checked is the secret
     * of THAT operator.
     *
     * @throws InvalidNotificationSignatureException when the notification is not authentic and recent
     */
    public function verifyNotification(Request $request, ?string $profileId = null): void;

    /**
     * After the signature is proven, and once the order is known: is it THIS address's order? The global address
     * only serves orders whose operator pays through the global account; an operator's address only serves that
     * operator's own orders. A valid signature of one account never settles another account's order.
     */
    public function ownsOrder(?string $notifiedProfileId, ProfilExploitant $orderProfile): bool;
}
