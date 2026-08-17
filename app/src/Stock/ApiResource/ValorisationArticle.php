<?php

declare(strict_types=1);

namespace App\Stock\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Stock\State\ValorisationArticleProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/** `GET /stock/articles/{id}/valorisation?date=` (RG-STOCK-18, §2.4 du plan, CA-14). */
#[ApiResource(
    shortName: 'StockValorisationArticle',
    operations: [
        new Get(
            uriTemplate: '/stock/articles/{id}/valorisation',
            security: "is_granted('PERM', 'stock.lire_valorisation')",
            provider: ValorisationArticleProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['valorisation:read']],
)]
final class ValorisationArticle
{
    #[ApiProperty(identifier: true)]
    #[Groups(['valorisation:read'])]
    public string $id = '';

    #[Groups(['valorisation:read'])]
    public string $date = '';

    #[Groups(['valorisation:read'])]
    public string $valorisation = '0.00';
}
