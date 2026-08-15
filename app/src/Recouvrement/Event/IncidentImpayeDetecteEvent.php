<?php

declare(strict_types=1);

namespace App\Recouvrement\Event;

use App\Recouvrement\Entity\IncidentImpaye;

/**
 * Dispatché à la création d'un `IncidentImpaye` (rejet détecté). Permet à la verticale propriétaire du
 * contrat (`$incident->getTypeRedevable()`) de synchroniser son propre statut métier (ex. `App\Sport`
 * bascule `AbonnementFitness.statut = Impaye`) sans que le moteur générique connaisse cette entité.
 */
final class IncidentImpayeDetecteEvent
{
    public function __construct(
        public readonly IncidentImpaye $incident,
        public readonly int $montantCentimes,
        public readonly \DateTimeImmutable $date,
    ) {
    }
}
