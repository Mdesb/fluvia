<?php

declare(strict_types=1);

namespace App\Sepa\Dto;

/**
 * Retour normalisé banque (rejet), relevé via `RetourSepaInterface` (§2.1/§4 du plan). Générique
 * (`endToEndId`/`mndtId`) — aucune dépendance à une verticale.
 */
final class RetourSepaDto
{
    public function __construct(
        public readonly string $endToEndId,
        public readonly string $mndtId,
        public readonly string $codeMotif,
        public readonly ?string $libelleMotif,
        public readonly int $montantCentimes,
        public readonly \DateTimeImmutable $dateReception,
    ) {
    }
}
