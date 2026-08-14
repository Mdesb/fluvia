<?php

declare(strict_types=1);

namespace App\Acces\Dto;

use App\Acces\Enum\EtatControleur;

/** DTO domaine (§2.1) : état/vivacité d'un contrôleur, alimente la supervision (§4.6). */
final class EtatControleurDto
{
    public function __construct(
        public readonly EtatControleur $etat,
        public readonly ?\DateTimeImmutable $dernierHeartbeat,
    ) {
    }
}
