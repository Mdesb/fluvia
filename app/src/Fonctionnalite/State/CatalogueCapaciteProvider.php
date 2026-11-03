<?php

declare(strict_types=1);

namespace App\Fonctionnalite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fonctionnalite\ApiResource\CapaciteCatalogueItem;
use App\Fonctionnalite\Dto\DescripteurCapacite;
use App\Fonctionnalite\Service\CatalogueCapacites;
use App\Platform\Module\ModuleGuard;

/**
 * @implements ProviderInterface<list<CapaciteCatalogueItem>>
 */
final class CatalogueCapaciteProvider implements ProviderInterface
{
    public function __construct(
        private readonly CatalogueCapacites $catalogue,
        private readonly ModuleGuard $moduleGuard,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $coeur = $this->moduleGuard->coreCapabilities();

        return array_map(static function (DescripteurCapacite $d) use ($coeur): CapaciteCatalogueItem {
            $item = self::versDto($d);
            $item->estDuCoeur = \in_array($d->code, $coeur, true);

            return $item;
        }, $this->catalogue->toutes());
    }

    private static function versDto(DescripteurCapacite $descripteur): CapaciteCatalogueItem
    {
        $item = new CapaciteCatalogueItem();
        $item->code = $descripteur->code;
        $item->libelle = $descripteur->libelle;
        $item->description = $descripteur->description;
        $item->categorie = $descripteur->categorie;
        $item->estVerticale = $descripteur->estVerticale;

        return $item;
    }
}
