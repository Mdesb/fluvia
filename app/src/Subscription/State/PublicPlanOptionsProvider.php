<?php

declare(strict_types=1);

namespace App\Subscription\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Subscription\ApiResource\PublicPlanOption;
use App\Subscription\Service\OfferCatalog;

/**
 * Les options en vente, pour le site vitrine (ED-5).
 *
 * `activeOptions()` rend déjà les seules options actives, indexées par capacité. On les réordonne par
 * libellé : une liste de prix dont l'ordre change d'un chargement à l'autre donne l'impression que le
 * catalogue bouge, et c'est la dernière impression qu'une page de tarifs doit donner.
 *
 * @implements ProviderInterface<PublicPlanOption>
 */
final class PublicPlanOptionsProvider implements ProviderInterface
{
    public function __construct(private readonly OfferCatalog $catalog)
    {
    }

    /** @return list<PublicPlanOption> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $items = [];

        foreach ($this->catalog->activeOptions() as $capability => $option) {
            $item = new PublicPlanOption();
            $item->capability = (string) $capability;
            $item->label = $option->getLabel();
            $item->monthlyPriceCents = $option->getMonthlyPriceCents();

            $items[] = $item;
        }

        usort($items, static fn (PublicPlanOption $a, PublicPlanOption $b): int => $a->label <=> $b->label);

        return $items;
    }
}
