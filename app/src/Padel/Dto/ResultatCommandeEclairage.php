<?php

declare(strict_types=1);

namespace App\Padel\Dto;

/**
 * DTO propre à Padel (§2.1 du plan) — dupliqué localement plutôt que réutilisé depuis
 * `App\Acces\Dto\ResultatCommande` (forme triviale, éviter un couplage Padel→Acces sans besoin
 * fonctionnel).
 */
final class ResultatCommandeEclairage
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
    ) {
    }

    public static function ok(string $message = ''): self
    {
        return new self(true, $message);
    }

    public static function echec(string $message): self
    {
        return new self(false, $message);
    }
}
