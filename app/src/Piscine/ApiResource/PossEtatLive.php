<?php

declare(strict_types=1);

namespace App\Piscine\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Piscine\State\PossEtatLiveProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Tableau de bord POSS temps réel (US-L6-03, CA-3) : présents / seuil POSS / pré-alerte / places
 * réservées restantes. Compose `JaugeFmi` (L3, lecture seule) et les réservations piscine, sans
 * toucher à `App\Acces\ApiResource\Supervision` (générique, non piscine-spécifique).
 */
#[ApiResource(
    shortName: 'PossEtatLive',
    operations: [
        new Get(
            uriTemplate: '/piscine/poss/{id}/etat',
            security: "is_granted('PERM', 'piscine.lire')",
            provider: PossEtatLiveProvider::class,
            normalizationContext: ['groups' => ['poss_live:read']],
        ),
    ],
)]
final class PossEtatLive
{
    #[ApiProperty(identifier: true)]
    #[Groups(['poss_live:read'])]
    public string $id = '';

    #[Groups(['poss_live:read'])]
    public int $presents = 0;

    #[Groups(['poss_live:read'])]
    public int $seuilPoss = 0;

    #[Groups(['poss_live:read'])]
    public bool $preAlerteAtteinte = false;

    #[Groups(['poss_live:read'])]
    public int $placesReserveesRestantes = 0;
}
