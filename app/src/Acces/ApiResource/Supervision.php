<?php

declare(strict_types=1);

namespace App\Acces\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Acces\State\SupervisionProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Vue live agrégée (US-L3-06, écran A-03, CA-7) : jauges FMI par espace, contrôleurs (état réseau)
 * et incidents (refus récents, seuil atteint, contrôleur hors-ligne). N'est pas une entité Doctrine.
 */
#[ApiResource(
    shortName: 'Supervision',
    operations: [
        new Get(
            uriTemplate: '/acces/supervision',
            security: "is_granted('PERM', 'acces.superviser')",
            provider: SupervisionProvider::class,
            normalizationContext: ['groups' => ['supervision:read']],
        ),
    ],
)]
final class Supervision
{
    #[ApiProperty(identifier: true)]
    #[Groups(['supervision:read'])]
    public string $id = 'live';

    /** @var list<array<string, mixed>> */
    #[Groups(['supervision:read'])]
    public array $jauges = [];

    /** @var list<array<string, mixed>> */
    #[Groups(['supervision:read'])]
    public array $controleurs = [];

    /** @var list<array<string, mixed>> */
    #[Groups(['supervision:read'])]
    public array $incidents = [];
}
