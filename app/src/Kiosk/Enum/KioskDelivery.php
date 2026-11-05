<?php

declare(strict_types=1);

namespace App\Kiosk\Enum;

/**
 * Ce que la borne remet à l'usager une fois la vente payée et validée.
 *
 * - `Ticket` : le ticket imprimé, qui porte le QR code de chaque support (un par unité si le point de
 *   vente le règle ainsi). Il ouvre le tourniquet comme le billet du guichet.
 * - `Badge` : un badge RFID encodé par l'encodeur de la borne ({@see \App\Kiosk\Port\BadgeEncoderInterface}).
 *   Tant qu'aucun encodeur n'est raccordé (#337), la borne le DIT et imprime le ticket à la place :
 *   l'usager a payé, il repart toujours avec un titre.
 */
enum KioskDelivery: string
{
    case Ticket = 'ticket';
    case Badge = 'badge';
}
