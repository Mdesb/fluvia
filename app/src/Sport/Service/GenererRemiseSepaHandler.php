<?php

declare(strict_types=1);

namespace App\Sport\Service;

use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\RemiseSepa;
use App\Sepa\Service\GenerationRemiseHandler;
use App\Sport\Sepa\SportEcheanceSepaSource;

/**
 * Génère et transmet une remise SEPA (pain.008 réel) pour toutes les échéances fitness dues jusqu'à
 * une date donnée (§2.1 du plan-sport, §3/§5 du plan-sepa). Délègue entièrement au module partagé
 * `App\Sepa` via le port `EcheanceSepaSource` (`SportEcheanceSepaSource`) — le moteur pain.008,
 * bi-régime, `SeqTp`, transmission, etc. ne sont plus dupliqués dans Sport.
 */
final class GenererRemiseSepaHandler
{
    public function __construct(
        private readonly GenerationRemiseHandler $generationRemiseHandler,
        private readonly SportEcheanceSepaSource $source,
    ) {
    }

    public function generer(Etablissement $etablissement, \DateTimeImmutable $dateExecutionPrevue): RemiseSepa
    {
        return $this->generationRemiseHandler->generer($etablissement, $dateExecutionPrevue, $this->source);
    }
}
