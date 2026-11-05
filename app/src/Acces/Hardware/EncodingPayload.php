<?php

declare(strict_types=1);

namespace App\Acces\Hardware;

/**
 * Ce qu'un encodeur écrit sur un support (#337). Volontairement minimal et sans donnée personnelle :
 * l'identifiant du droit et sa fin de validité suffisent à une porte qui relit la carte ; le nom du
 * porteur n'a rien à faire sur un bracelet qu'on peut perdre au bord d'un bassin.
 */
final readonly class EncodingPayload
{
    public function __construct(
        public string $identifier,
        public string $rightId,
        public ?\DateTimeImmutable $validUntil,
        public int $headcount = 1,
    ) {
    }
}
