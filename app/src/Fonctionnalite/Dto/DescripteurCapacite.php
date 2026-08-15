<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Dto;

/**
 * Fiche descriptive d'une capacité du catalogue (code, libellé, description, catégorie) — donnée de
 * référence, non persistée (cf. `App\Fonctionnalite\Service\CatalogueCapacites`).
 */
final class DescripteurCapacite
{
    public function __construct(
        public readonly string $code,
        public readonly string $libelle,
        public readonly string $description,
        public readonly string $categorie,
    ) {
    }
}
