<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

/**
 * A notification whose signature cannot be trusted. Carries the rejection reason only: no free-form
 * message, so no secret, header or body can leak through it.
 */
final class InvalidNotificationSignatureException extends \RuntimeException
{
    public function __construct(public readonly NotificationRejection $reason)
    {
        parent::__construct('notification_signature_' . $reason->value);
    }
}
