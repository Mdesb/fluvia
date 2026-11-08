<?php

declare(strict_types=1);

namespace App\Acces\Dto;

use App\Securite\Entity\Utilisateur;

/**
 * Contexte d'une commande d'ouverture (§2.1) : porte l'agent/motif pour une ouverture manuelle
 * tracée (US-L3-06) ; absent pour un franchissement automatique validé par l'algorithme (§4.3).
 */
final class OuvertureContexte
{
    public function __construct(
        public readonly bool $manuelle = false,
        public readonly ?Utilisateur $agent = null,
        public readonly ?string $motif = null,
        /** Personnes à laisser passer (rotations d'un tripode) : 1, sauf titre de groupe (#337). */
        public readonly int $headcount = 1,
    ) {
    }
}
