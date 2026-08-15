<?php

declare(strict_types=1);

namespace App\Recouvrement\Event;

use App\Recouvrement\Entity\IncidentImpaye;

/**
 * Dispatché quand un `IncidentImpaye` est résolu (représentation réussie ou résolution 1 clic) : la
 * verticale propriétaire peut réactiver son propre statut métier et projeter l'encaissement en compta.
 */
final class IncidentImpayeResoluEvent
{
    public function __construct(
        public readonly IncidentImpaye $incident,
        public readonly int $montantCentimes,
        public readonly \DateTimeImmutable $date,
        public readonly string $origine,
    ) {
    }
}
