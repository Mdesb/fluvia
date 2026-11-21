<?php

declare(strict_types=1);

namespace App\Boutique\Paiement\Stripe;

use App\Boutique\Paiement\InvalidNotificationSignatureException;
use App\Boutique\Paiement\NotificationRejection;

/**
 * Stripe webhook signature check (docs/references/stripe-webhooks.md). Pure: no state, no I/O, the clock
 * is a parameter.
 *
 * Header: comma-separated `key=value` entries; only `t` and every `v1` are kept, everything else
 * (`v0`, unknown keys) is ignored. Signed message: `t . '.' . raw body`. HMAC-SHA256, hex, keyed with the
 * whole secret (`whsec_` prefix included). Constant-time comparison against every `v1`, then recency.
 */
final class StripeSignatureChecker
{
    public const DEFAULT_TOLERANCE = 300;

    /** @throws InvalidNotificationSignatureException */
    public function verify(string $header, string $body, string $secret, int $now, int $tolerance = self::DEFAULT_TOLERANCE): void
    {
        if ($secret === '') {
            throw new InvalidNotificationSignatureException(NotificationRejection::NotConfigured);
        }
        if (trim($header) === '') {
            throw new InvalidNotificationSignatureException(NotificationRejection::Missing);
        }

        $timestamps = [];
        $signatures = [];
        foreach (explode(',', $header) as $element) {
            $pair = explode('=', $element, 2);
            if (\count($pair) !== 2) {
                continue;
            }
            $key = trim($pair[0]);
            $value = trim($pair[1]);
            if ($key === 't') {
                $timestamps[] = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }
        if (\count($timestamps) !== 1 || preg_match('/^[0-9]{1,15}$/', $timestamps[0]) !== 1 || $signatures === []) {
            throw new InvalidNotificationSignatureException(NotificationRejection::Malformed);
        }

        $expected = hash_hmac('sha256', $timestamps[0] . '.' . $body, $secret);
        $matched = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $matched = true;
            }
        }
        if (!$matched) {
            throw new InvalidNotificationSignatureException(NotificationRejection::Invalid);
        }

        if (abs($now - (int) $timestamps[0]) > $tolerance) {
            throw new InvalidNotificationSignatureException(NotificationRejection::OutOfWindow);
        }
    }
}
