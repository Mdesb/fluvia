<?php

declare(strict_types=1);

namespace App\Sport\Sepa\Dto;

/** Résultat de la tokenisation d'un IBAN — l'IBAN clair n'est jamais transporté au-delà (§2.2 du plan). */
final class TokenIban
{
    public function __construct(
        public readonly string $token,
        public readonly string $quatreDerniers,
    ) {
    }
}
