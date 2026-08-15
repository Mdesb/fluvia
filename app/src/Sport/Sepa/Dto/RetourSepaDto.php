<?php

declare(strict_types=1);

namespace App\Sport\Sepa\Dto;

use Symfony\Component\Uid\Uuid;

/** Retour normalisé banque (rejet initial ou représentation), relevé via `CollecteurSepaInterface` (§2.1). */
final class RetourSepaDto
{
    public function __construct(
        public readonly Uuid $echeanceId,
        public readonly string $codeRetour,
        public readonly ?string $libelleRetour,
        public readonly int $montantCentimes,
        public readonly \DateTimeImmutable $dateReception,
    ) {
    }
}
