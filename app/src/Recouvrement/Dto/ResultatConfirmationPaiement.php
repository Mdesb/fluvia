<?php

declare(strict_types=1);

namespace App\Recouvrement\Dto;

/** Résultat de confirmation d'un encaissement CB immédiat (moteur partagé). */
final class ResultatConfirmationPaiement
{
    public function __construct(
        public readonly bool $confirme,
        public readonly \DateTimeImmutable $dateConfirmation,
    ) {
    }
}
