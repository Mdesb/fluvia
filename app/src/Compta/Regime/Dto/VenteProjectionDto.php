<?php

declare(strict_types=1);

namespace App\Compta\Regime\Dto;

use Symfony\Component\Uid\Uuid;

/**
 * Projection en lecture seule d'une `App\Vente\Entity\Vente` (M2) validée, prête pour la génération
 * d'écriture (RG-COMPTA-04). Produite par `App\Compta\Adapter\ProjectionVenteDoctrineAdapter`.
 */
final class VenteProjectionDto
{
    /**
     * @param list<LigneVenteProjectionDto> $lignes
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly string $numero,
        public readonly Uuid $etablissement,
        public readonly \DateTimeImmutable $date,
        public readonly array $lignes,
        public readonly int $totalTtcCentimes,
        /**
         * Les règlements de la vente, pour ventiler le débit d'encaissement par moyen.
         *
         * ⚠ Vide par défaut, et c'est ce qui rend le lot déployable : tout appelant existant
         * continue de produire une ligne de débit unique, sans rien changer.
         *
         * @var list<SettlementProjectionDto>
         */
        public readonly array $reglements = [],
    ) {
    }
}
