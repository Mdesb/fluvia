<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/** Mode de décompte d'une réservation (RG-M5-02). */
enum ModeDecompteReservation: string
{
    case QuotaFormule = 'quota_formule';

    /**
     * CQ-3 + CQ-6 (D23 pt 4, D24) — carte de N réservations : un **stock** qui se vide, porté par un
     * droit d'accès de type carte, décompté **à la réservation**.
     *
     * Ne pas confondre avec `QuotaFormule`, qui est un quota **calendaire sans report** (RG-M1-12) :
     * les deux partagent le point de consommation, jamais la mécanique. Un même client peut porter
     * les deux — deux aquagym par semaine incluses dans son abonnement, *et* une carte de dix
     * massages achetée à part —, et les fondre produirait des décomptes faux dans les deux sens (D24).
     */
    case CarteStock = 'carte_stock';
    case VenteUnite = 'vente_unite';
    case Gratuit = 'gratuit';
}
