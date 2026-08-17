<?php

declare(strict_types=1);

namespace App\Stock\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Stock\State\AlertesReapproProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * `GET /stock/alertes-reappro` (RG-STOCK-10, §3.3 du plan) : lecture pure, aucune entité persistée —
 * l'alerte disparaît de facto dès que la disponibilité repasse au-dessus du seuil.
 */
#[ApiResource(
    shortName: 'StockAlerteReappro',
    operations: [
        new GetCollection(
            uriTemplate: '/stock/alertes-reappro',
            security: "is_granted('PERM', 'stock.lire')",
            provider: AlertesReapproProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['alerte_reappro:read']],
)]
final class AlerteReappro
{
    #[ApiProperty(identifier: true)]
    #[Groups(['alerte_reappro:read'])]
    public string $articleStock = '';

    #[Groups(['alerte_reappro:read'])]
    public string $libelle = '';

    #[Groups(['alerte_reappro:read'])]
    public string $codeEAN = '';

    #[Groups(['alerte_reappro:read'])]
    public int $disponibilite = 0;

    #[Groups(['alerte_reappro:read'])]
    public string $seuilMin = '0.000';

    #[Groups(['alerte_reappro:read'])]
    public string $seuilMax = '0.000';

    #[Groups(['alerte_reappro:read'])]
    public string $quantiteSuggeree = '0.000';
}
