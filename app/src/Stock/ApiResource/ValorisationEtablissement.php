<?php

declare(strict_types=1);

namespace App\Stock\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Stock\State\ValorisationEtablissementProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/** `GET /stock/valorisation?etablissement=&date=` (RG-STOCK-18) : agrégat, somme par article. */
#[ApiResource(
    shortName: 'StockValorisationEtablissement',
    operations: [
        new GetCollection(
            uriTemplate: '/stock/valorisation',
            security: "is_granted('PERM', 'stock.lire_valorisation')",
            provider: ValorisationEtablissementProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['valorisation:read']],
)]
final class ValorisationEtablissement
{
    #[ApiProperty(identifier: true)]
    #[Groups(['valorisation:read'])]
    public string $articleStock = '';

    #[Groups(['valorisation:read'])]
    public string $libelle = '';

    #[Groups(['valorisation:read'])]
    public string $valorisation = '0.00';
}
