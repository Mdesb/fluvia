<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/**
 * Issue du crédit sur un no-show / une annulation tardive facturée (RG-CQ5-01, D24 — second axe
 * orthogonal à `ModeFacturationNoShow`, ne PAS fusionner). Paramétrable aux 4 portées de
 * `RegleAnnulation`, défaut `RestoredWithReschedule` (D27, plan-cq5.md §3.1). ⚠ Le sens de
 * `Decremented` vs `Restored` repose sur l'hypothèse §3.3 de `spec-cq5-noshow-credit.md` (décompte au
 * booking, pas au passage) — à confirmer par CQ-3/CQ-6, cf. plan-cq5.md §8 risque n°1.
 */
enum IssueCreditNoShow: string
{
    /** Le crédit déjà pris (hypothèse §3.3) reste pris : aucune écriture supplémentaire. */
    case Decremented = 'decremented';

    /** Le crédit est rendu — `+1` atomique, sans promesse de report. */
    case Restored = 'restored';

    /** Même restitution que `Restored`, plus publication de `booking.reschedule_requested` (D27, SF-2 absent). */
    case RestoredWithReschedule = 'restored_with_reschedule';
}
