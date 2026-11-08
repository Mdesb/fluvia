<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

/**
 * A REAL provider (#339): it only takes over from the demonstration stub of its regime once its
 * configuration is complete. Until then the selector keeps the stub — which refuses to run outside
 * preproduction (`StubPaymentReceiptSigner`, fail-closed). A half-configured provider is therefore never
 * called: it would turn every purchase into a 502 instead of a clear "not configured".
 */
interface ConfigurableOnlinePaymentInterface
{
    public function isConfigured(): bool;
}
