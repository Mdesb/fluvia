<?php

declare(strict_types=1);

namespace App\PublicApi\Webhook;

use App\Platform\Event\DomainEvent;
use App\PublicApi\Service\SupportReferenceSigner;
use Symfony\Component\Uid\Uuid;

/**
 * Le catalogue FERMÉ des événements qu'une application partenaire peut recevoir, et le contrat de
 * chacun (spec API partenaire v1, §3.3).
 *
 * ⚠ **UNE LISTE FERMÉE, QU'ON ÉTEND À LA MAIN.** Un événement interne n'est jamais relayé tel quel :
 * chacun a ici son `data` composé champ par champ — aucun nom, aucune adresse, aucun identifiant de
 * client ; un support n'apparaît que par sa référence opaque propre à l'application (le même
 * `SupportReferenceSigner` que `/v1/access-rights`). Les événements `access.right_granted/revoked/
 * expired` s'ajouteront ici quand le module Accès les émettra.
 *
 * `version` est celle du contrat de `data` : on l'incrémente quand un champ change de sens ou disparaît.
 */
final class PartnerEventCatalog
{
    public const VERSION = 1;

    public const EVENTS = [
        'access.card_recharged',
        'booking.cancelled',
        'booking.no_show',
        'payment.failed',
        'payment.succeeded',
    ];

    public function __construct(
        private readonly SupportReferenceSigner $signer,
    ) {
    }

    /** @return array<string, mixed> le `data` de l'enveloppe, tel que CETTE application le reçoit */
    public function data(DomainEvent $event, Uuid $application): array
    {
        $p = $event->payload;

        return match ($event->name->value) {
            'access.card_recharged' => [
                'supportReference' => \is_string($p['supportId'] ?? null) && Uuid::isValid($p['supportId'])
                    ? $this->signer->reference($application, Uuid::fromString($p['supportId']))
                    : null,
                'creditsAdded' => $p['creditsAdded'] ?? null,
                'creditBalanceAfter' => $p['creditBalanceAfter'] ?? null,
                'validUntil' => $p['newExpiryAt'] ?? null,
            ],
            'booking.cancelled' => [
                'bookingId' => $event->subject->id,
                'slotId' => $p['slotId'] ?? null,
                'leadTimeMinutes' => $p['leadTimeMinutes'] ?? null,
                'withinFreeWindow' => $p['withinFreeWindow'] ?? null,
            ],
            'booking.no_show' => [
                'bookingId' => $event->subject->id,
                'amountAtRisk' => $p['amountAtRisk'] ?? null,
            ],
            'payment.failed' => [
                'amountCents' => $p['amount_cents'] ?? null,
                'reason' => $p['cause'] ?? null,
                'rejectedAt' => $p['rejected_at'] ?? null,
            ],
            'payment.succeeded' => [
                'amountCents' => $p['amount_cents'] ?? null,
                'origin' => $p['origin'] ?? null,
                'settledAt' => $p['settled_at'] ?? null,
            ],
            default => throw new \LogicException(sprintf('« %s » n’est pas au catalogue partenaire.', $event->name->value)),
        };
    }
}
