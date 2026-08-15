<?php

declare(strict_types=1);

namespace App\Compta\Regime;

use App\Compta\Entity\MappingComptable;
use Symfony\Component\Uid\Uuid;

/**
 * Résout le `MappingComptable` d'une catégorie comptable (M1) sans que le moteur de régime touche à
 * la persistance : préchargé et validé en amont par `MappingComptableGuard`.
 */
final class MappingResolver
{
    /** @param array<string, MappingComptable> $parCategorie indexé par UUID de catégorie (string) */
    public function __construct(
        private readonly array $parCategorie,
    ) {
    }

    public function pour(Uuid $categorieId): ?MappingComptable
    {
        return $this->parCategorie[(string) $categorieId] ?? null;
    }
}
