<?php

declare(strict_types=1);

namespace App\Group\Enum;

/**
 * Grain de décompte d'une réservation de groupe, choisi par réservation (arbitrage Maxime, 08/09).
 *
 * `PerGroup` : une seule `Reservation` socle de quantité N décompte la jauge — le groupe pèse comme un
 * bloc. `PerPerson` : N `Reservation` de quantité 1, une par visiteur — c'est ce que veut le musée pour
 * un billet / un droit d'accès nominatif par personne. Les deux consomment la même jauge (somme = N).
 */
enum GroupBookingGrain: string
{
    case PerGroup = 'per_group';
    case PerPerson = 'per_person';
}
