<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/** Statut de règlement d'une part de paiement partagé (RG-M5-10). */
enum StatutPaiementParticipant: string
{
    case Paye = 'paye';
    case EnAttente = 'en_attente';
    case ImputeOrganisateur = 'impute_organisateur';
}
