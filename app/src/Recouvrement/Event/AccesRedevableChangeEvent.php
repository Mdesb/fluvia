<?php

declare(strict_types=1);

namespace App\Recouvrement\Event;

/**
 * Dispatché par `PropagationAccesHandler` à chaque bascule d'accès décidée par le moteur de
 * recouvrement (valide/dévalidé). Permet à la verticale propriétaire du contrat de tenir à jour sa
 * propre projection d'accès (ex. `App\Sport\Entity\StatutAccesFitness.actif/motifInactivite`) — le
 * `DroitAcces` L3 est déjà écrit par `PropagationAccesHandler` lui-même avant ce dispatch.
 */
final class AccesRedevableChangeEvent
{
    public function __construct(
        public readonly string $typeRedevable,
        public readonly string $referenceRedevable,
        public readonly bool $actif,
    ) {
    }
}
