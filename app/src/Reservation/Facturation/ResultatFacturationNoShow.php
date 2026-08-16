<?php

declare(strict_types=1);

namespace App\Reservation\Facturation;

/** Résultat de l'application d'une stratégie de facturation no-show (T19 du plan). */
final class ResultatFacturationNoShow
{
    public function __construct(
        public readonly bool $succes,
        public readonly ?string $motif = null,
    ) {
    }
}
