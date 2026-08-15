<?php

declare(strict_types=1);

namespace App\Recouvrement\Event;

use App\Recouvrement\Entity\IncidentImpaye;

/**
 * Dispatché lors d'une réouverture forcée par un agent habilité (RG-SOCLE-07) : contrairement à
 * `IncidentImpayeResoluEvent`, le dossier reste ouvert (aucun encaissement) — seul l'accès est restauré
 * à titre d'exception.
 */
final class IncidentImpayeReouvertureForceeEvent
{
    public function __construct(
        public readonly IncidentImpaye $incident,
    ) {
    }
}
