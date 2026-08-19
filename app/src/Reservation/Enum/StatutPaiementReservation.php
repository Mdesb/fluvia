<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/**
 * Statut de paiement observable d'une Reservation, dérivé de `Vente.statut` (RG-RESAENC-03) : jamais
 * une source de vérité indépendante, jamais persisté (calculé à l'appel, cf. `Reservation::statutPaiement()`).
 */
enum StatutPaiementReservation: string
{
    case SansObjet = 'sans_objet';
    case APayer = 'a_payer';
    case Payee = 'payee';
    case Annulee = 'annulee';
}
