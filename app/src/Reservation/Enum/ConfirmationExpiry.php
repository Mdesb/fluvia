<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/**
 * Ce qu'il advient d'une reservation que personne n'a confirmee avant l'echeance.
 *
 * ⚠ TROIS COMPORTEMENTS, ET AUCUN N'EST IMPOSE. Maxime : « ces decisions sont des decisions metier,
 * il faut laisser le choix a l'exploitant ». Un club qui refuse de perdre un creneau et un club qui
 * refuse de bloquer un terrain vide ont tous les deux raison, chez eux.
 *
 * Nom anglais (D5) : enum AJOUTE, donc classe et cas en anglais.
 */
enum ConfirmationExpiry: string
{
    /** Le creneau repart a la vente : la reservation est annulee sans frais. */
    case Release = 'release';

    /** Rien ne bouge : le terrain reste bloque, un agent tranchera. */
    case Keep = 'keep';

    /** Le creneau repart a la vente ET la reservation est facturee, comme un no-show. */
    case ReleaseAndCharge = 'release_and_charge';
}
