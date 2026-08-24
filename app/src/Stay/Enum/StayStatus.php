<?php

declare(strict_types=1);

namespace App\Stay\Enum;

/**
 * Cycle de vie d'un séjour (D16). Trois états, pas davantage : un séjour n'est pas un workflow, c'est
 * un compte ouvert tant que le client est sur place.
 *
 * **`Closed` et `Settled` sont volontairement distincts.** Un client peut partir sans que son compte
 * soit soldé — litige sur une ligne, prise en charge par un comité d'entreprise, facturation différée
 * à une société. Fusionner les deux états rendrait ce cas inexprimable et forcerait soit à retenir le
 * départ, soit à encaisser un montant faux. La sortie physique et le règlement sont deux faits
 * différents, et le modèle doit pouvoir les dater séparément.
 */
enum StayStatus: string
{
    /** Le client est sur place ; le compte accepte de nouvelles lignes. */
    case Open = 'open';

    /** Le client est parti ; le compte est figé mais peut rester débiteur. */
    case Closed = 'closed';

    /** Le compte est soldé — plus aucune ligne, plus aucun reste à payer. */
    case Settled = 'settled';
}
