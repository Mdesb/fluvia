<?php

declare(strict_types=1);

namespace App\Subscription\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Subscription\State\PublicPlanOptionsProvider;

/**
 * Les options en vente, lisibles sans compte (ED-5).
 *
 * Une option est un module (RG-ED-03) : ce que la vitrine annonce ici est exactement ce que
 * `hasModule()` activera après paiement. C'est la même donnée, pas un second catalogue commercial
 * qui divergerait du catalogue technique — le prospect ne peut donc pas voir une option qu'on ne
 * saurait pas lui livrer.
 */
#[ApiResource(
    shortName: 'PublicPlanOption',
    operations: [
        new GetCollection(
            uriTemplate: '/editor/plan-options',
            security: "is_granted('PUBLIC_ACCESS')",
            provider: PublicPlanOptionsProvider::class,
        ),
    ],
)]
final class PublicPlanOption
{
    #[ApiProperty(identifier: true)]
    public string $capability = '';

    public string $label = '';

    public int $monthlyPriceCents = 0;
}
