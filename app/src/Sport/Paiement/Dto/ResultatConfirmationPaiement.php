<?php

declare(strict_types=1);

namespace App\Sport\Paiement\Dto;

/** Résultat de confirmation d'un encaissement CB immédiat (§2.3 du plan). */
final class ResultatConfirmationPaiement
{
    public function __construct(
        public readonly bool $confirme,
        public readonly \DateTimeImmutable $dateConfirmation,
    ) {
    }
}
