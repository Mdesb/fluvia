<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Statut de paiement des frais d'inscription d'une paire à un tournoi (§4.5). */
enum StatutPaiementInscriptionTournoi: string
{
    case Paye = 'paye';
    case EnAttente = 'en_attente';
}
