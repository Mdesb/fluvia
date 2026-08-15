<?php

declare(strict_types=1);

namespace App\Fonctionnalite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fonctionnalite\ApiResource\CapaciteCatalogueItem;
use App\Fonctionnalite\Dto\DescripteurCapacite;
use App\Fonctionnalite\Service\CatalogueCapacites;

/**
 * @implements ProviderInterface<list<CapaciteCatalogueItem>>
 */
final class CatalogueCapaciteProvider implements ProviderInterface
{
    public function __construct(
        private readonly CatalogueCapacites $catalogue,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(self::versDto(...), $this->catalogue->toutes());
    }

    private static function versDto(DescripteurCapacite $descripteur): CapaciteCatalogueItem
    {
        $item = new CapaciteCatalogueItem();
        $item->code = $descripteur->code;
        $item->libelle = $descripteur->libelle;
        $item->description = $descripteur->description;
        $item->categorie = $descripteur->categorie;

        return $item;
    }
}
