<?php

declare(strict_types=1);

namespace App\PublicApi\Webhook;

use App\Platform\Message\AsyncMessage;

/**
 * Livrer UNE livraison de webhook partenaire. Ne porte que son identifiant : l'état (corps, tentatives,
 * consentement) est relu en base à chaque tentative. Routé vers le transport `async` (en base).
 */
final class DeliverPartnerWebhook implements AsyncMessage
{
    public function __construct(
        public readonly string $deliveryId,
    ) {
    }
}
