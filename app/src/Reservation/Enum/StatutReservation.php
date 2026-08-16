<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/** Cycle de vie d'une Réservation (cahier §6, RG-M5-01/02/09). */
enum StatutReservation: string
{
    case Confirmee = 'confirmee';
    case ListeAttente = 'liste_attente';
    case AnnuleeLibre = 'annulee_libre';
    case AnnuleeTardiveFacturee = 'annulee_tardive_facturee';
    case NoShowFacture = 'no_show_facture';
    case Honoree = 'honoree';

    /** Vrai si la réservation occupe encore une place sur le créneau (compte pour la jauge). */
    public function occupePlace(): bool
    {
        return $this === self::Confirmee || $this === self::Honoree;
    }
}
