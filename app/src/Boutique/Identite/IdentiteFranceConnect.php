<?php

declare(strict_types=1);

namespace App\Boutique\Identite;

/** Identité renvoyée par le fournisseur FranceConnect (§2.1 plan-boutique.md, ⚠ intégration à cadrer). */
final class IdentiteFranceConnect
{
    public function __construct(
        public readonly string $sub,
        public readonly string $email,
        public readonly string $nom,
        public readonly string $prenom,
        public readonly ?\DateTimeImmutable $dateNaissance = null,
    ) {
    }
}
