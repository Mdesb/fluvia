<?php

declare(strict_types=1);

namespace App\Compta\Port;

/**
 * Frontière module Accès (L3, lecture seule) : fait générateur de la reprise PCA « au passage »
 * (RG-M6-03). N'expose que le **cumul de passages autorisés**, jamais la jauge FMI (RG-ACC-04, garde
 * de nommage explicite — `compterPassagesAutorises`, pas `jauge*`).
 */
interface ProjectionPassageInterface
{
    /** Nombre de passages autorisés consommés pour ce support depuis une date (cumul, jamais la FMI). */
    public function compterPassagesAutorises(string $identifiantSupport, \DateTimeImmutable $depuis): int;
}
