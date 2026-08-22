<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/**
 * Nature du droit projeté (RG-M1-03/04) : pilote le décompte de crédit au passage (RG-ACC-02).
 *
 * `Personnel` (App\Personnel\Entity\BadgeStaff, RG-PERSO-06/07) : extension additive coordonnée
 * (plan-personnel.md §0 décision n°1 / §2) — **unique** modification requise dans `App\Acces` par le
 * module Personnel. Aucun impact sur le moteur : `ValidationPassageHandler` ne teste `sourceType`
 * qu'une fois, pour le décompte crédit `CarteQuota` (étape 7) — un droit `Personnel` suit exactement
 * le chemin d'un `Billet` (pas de décompte). Aucune migration de schéma requise (`source_type` déjà
 * `VARCHAR(24)`).
 *
 * `Booking` (ProjectionAccesReservationHandler, module socle Réservation, RG-ACC3-01/02/03,
 * plan-acc3.md §1) : deuxième extension additive coordonnée du même genre — un droit issu d'une
 * réservation suit lui aussi le chemin générique (pas de décompte crédit). Nommage anglais (D5),
 * aucune migration de schéma requise.
 */
enum TypeDroitAcces: string
{
    case Billet = 'billet';
    case Abonnement = 'abonnement';
    case CarteQuota = 'carte_quota';
    case Personnel = 'personnel';
    case Booking = 'booking';
}
