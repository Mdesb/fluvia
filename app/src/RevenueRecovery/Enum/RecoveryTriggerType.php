<?php

declare(strict_types=1);

namespace App\RevenueRecovery\Enum;

/**
 * Type de déclencheur d'une séquence/d'un dossier de relance (plan-revenue-recovery.md §1). Huit
 * valeurs déclarées dès I1 : quatre réellement câblées (`BookingCancelled`, `BookingNoShow`,
 * `PaymentFailed`, `PaymentIncidentReopened` — `App\RevenueRecovery\EventListener\RevenueRecoveryEventSubscriber`),
 * quatre réservées pour I2 (bloquées par RR-1, hors périmètre de ce lot — `CartAbandoned`,
 * `InvoiceOverdue`, `QuoteExpired`, `CustomerInactive`, aucun code ne les déclenche encore mais une
 * `RecoverySequence` peut déjà être paramétrée dessus, RG-RR-02 : elle reste simplement inactive faute
 * d'émetteur).
 */
enum RecoveryTriggerType: string
{
    case CartAbandoned = 'cart_abandoned';
    case InvoiceOverdue = 'invoice_overdue';
    case BookingCancelled = 'booking_cancelled';
    case BookingNoShow = 'booking_no_show';
    case PaymentFailed = 'payment_failed';
    case PaymentIncidentReopened = 'payment_incident_reopened';
    case QuoteExpired = 'quote_expired';
    case CustomerInactive = 'customer_inactive';
}
