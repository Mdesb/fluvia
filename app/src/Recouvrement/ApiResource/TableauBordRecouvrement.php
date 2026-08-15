<?php

declare(strict_types=1);

namespace App\Recouvrement\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Recouvrement\State\TableauBordRecouvrementProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Tableau de bord du recouvrement (moteur générique partagé, extrait de
 * `App\Sport\ApiResource\TableauBordImpayes`) : file des rejets par statut, accès bloqués en cours,
 * taux de résolution en self-service. Agrégé par établissement actif (RG-SOCLE-05).
 */
#[ApiResource(
    shortName: 'TableauBordRecouvrement',
    operations: [
        new Get(
            uriTemplate: '/recouvrement/tableau-bord',
            security: "is_granted('PERM', 'recouvrement.piloter')",
            provider: TableauBordRecouvrementProvider::class,
            normalizationContext: ['groups' => ['dashboard_recouvrement:read']],
        ),
    ],
)]
final class TableauBordRecouvrement
{
    #[ApiProperty(identifier: true)]
    #[Groups(['dashboard_recouvrement:read'])]
    public string $id = 'tableau-bord-recouvrement';

    #[Groups(['dashboard_recouvrement:read'])]
    public int $nbEnRepresentation = 0;

    #[Groups(['dashboard_recouvrement:read'])]
    public int $nbEnRecouvrement = 0;

    #[Groups(['dashboard_recouvrement:read'])]
    public int $nbAccesBloques = 0;

    #[Groups(['dashboard_recouvrement:read'])]
    public float $tauxResolutionSelfService = 0.0;
}
