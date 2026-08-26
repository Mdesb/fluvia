<?php

declare(strict_types=1);

namespace App\Lodging\Domain;

/**
 * Une unité d'hébergement occupée sur une période (ACT-2).
 *
 * `unitId` est une **chaîne opaque** et non une entité `Ressource`. C'est délibéré : le calendrier est
 * du calcul pur, il doit être éprouvable sans base ni conteneur, et `App\Lodging` n'a pas à connaître
 * le modèle de `App\Reservation` pour compter des nuits (D2). L'adaptateur qui lira les affectations
 * réelles viendra à part, et il sera mince.
 */
final class UnitOccupancy
{
    public function __construct(
        public readonly string $unitId,
        public readonly LodgingPeriod $period,
    ) {
    }
}
