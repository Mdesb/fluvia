<?php

declare(strict_types=1);

namespace App\Dining\Enum;

/**
 * Cycle de vie d'une addition (ACT-4).
 *
 * **`Closed` et `Settled` sont distincts pour la même raison que sur le séjour** : demander
 * l'addition et la payer sont deux faits différents, et une table part parfois avant d'avoir réglé —
 * note de frais d'entreprise, litige sur un plat, client qui revient le lendemain. Les confondre
 * obligerait soit à retenir le client, soit à encaisser un montant faux.
 */
enum OrderStatus: string
{
    /** Le service est en cours ; l'addition accepte de nouvelles lignes. */
    case Open = 'open';

    /** L'addition est demandée et figée. Elle peut rester impayée. */
    case Closed = 'closed';

    /** Réglée — plus rien à encaisser. */
    case Settled = 'settled';
}
