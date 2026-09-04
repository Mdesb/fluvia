<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/** Cycle de vie d'une Réservation (cahier §6, RG-M5-01/02/09). */
enum StatutReservation: string
{
    /**
     * ⚠ RESERVEE MAIS PAS ENCORE CONFIRMEE — l'etat neuf du 04/09.
     *
     * Sans lui, une reservation en attente de confirmation serait `Confirmee`, ce qui est
     * exactement le contraire. Aucune reservation n'entre dans cet etat tant qu'aucune
     * `RegleAnnulation` ne declare de delai de confirmation : le defaut reste `Confirmee`.
     */
    case AConfirmer = 'a_confirmer';

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
