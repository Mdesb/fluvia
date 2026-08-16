<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Statut du joueur pour la tarification (RG-PADEL-02), dérivé de l'adhésion M1/M4. */
enum StatutJoueurTarif: string
{
    case Membre = 'membre';
    case NonMembre = 'non_membre';
}
