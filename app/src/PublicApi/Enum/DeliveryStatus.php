<?php

declare(strict_types=1);

namespace App\PublicApi\Enum;

/**
 * L'état d'une livraison de webhook partenaire.
 *
 * `failed` = échec DÉFINITIF après le dernier réessai (visible côté éditeur) ; `abandoned` = fermée
 * sans envoi parce que l'accord `events:subscribe` a été retiré ou l'abonnement coupé entre-temps.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
}
