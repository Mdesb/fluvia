<?php

declare(strict_types=1);

namespace App\Sport\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Sport\State\TableauBordImpayesProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Tableau de bord impayés (US-SPORT-11, CA-13) : file des rejets par statut, badges refusés en cours,
 * taux de résolution en self-service. Même patron que `App\Piscine\ApiResource\PossEtatLive`.
 */
#[ApiResource(
    shortName: 'TableauBordImpayes',
    operations: [
        new Get(
            uriTemplate: '/sport/tableau-bord-impayes',
            security: "is_granted('PERM', 'sport.piloter_impayes')",
            provider: TableauBordImpayesProvider::class,
            normalizationContext: ['groups' => ['dashboard:read']],
        ),
    ],
)]
final class TableauBordImpayes
{
    #[ApiProperty(identifier: true)]
    #[Groups(['dashboard:read'])]
    public string $id = 'tableau-bord-impayes';

    #[Groups(['dashboard:read'])]
    public int $nbEnRepresentation = 0;

    #[Groups(['dashboard:read'])]
    public int $nbEnRecouvrement = 0;

    #[Groups(['dashboard:read'])]
    public int $nbBadgesRefuses = 0;

    #[Groups(['dashboard:read'])]
    public float $tauxResolutionSelfService = 0.0;
}
