<?php

declare(strict_types=1);

namespace App\Group\Enum;

/**
 * État de paiement d'une réservation de groupe — le paiement d'un groupe est presque toujours
 * **différé** (bon de commande, mandat administratif, tiers-payeur).
 *
 * `Pending` = rien d'engagé ; `PurchaseOrder` = bon de commande reçu ; `Invoiced` = facturé ;
 * `Paid` = réglé. La facturation elle-même passe par la chaîne `App\Facturation` / `App\Vente`.
 */
enum GroupPaymentStatus: string
{
    case Pending = 'pending';
    case PurchaseOrder = 'purchase_order';
    case Invoiced = 'invoiced';
    case Paid = 'paid';
}
