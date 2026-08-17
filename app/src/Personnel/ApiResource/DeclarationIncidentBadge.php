<?php

declare(strict_types=1);

namespace App\Personnel\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Personnel\State\AnnulerDeclarationIncidentBadgeProcessor;
use App\Personnel\State\DeclarationIncidentBadgeProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Vue API (décision n°8 du plan) : lit `App\Acces\Entity\DeclarationPerteVol` filtrée sur le(s)
 * `Support` d'un `BadgeStaff` — **pas de table propre**. L'écriture délègue intégralement à
 * `App\Acces\Service\BlocageSupportHandler` (zéro duplication de RG-ACC-07).
 */
#[ApiResource(
    shortName: 'DeclarationIncidentBadge',
    operations: [
        new GetCollection(
            uriTemplate: '/personnel/declarations-incident',
            security: "is_granted('PERM', 'personnel.lire')",
            provider: DeclarationIncidentBadgeProvider::class,
        ),
        new Get(
            uriTemplate: '/personnel/declarations-incident/{id}',
            security: "is_granted('PERM', 'personnel.lire')",
            provider: DeclarationIncidentBadgeProvider::class,
        ),
        new Post(
            uriTemplate: '/personnel/declarations-incident/{id}/annuler',
            read: false,
            input: false,
            security: "is_granted('PERM', 'personnel.gerer_badge') or is_granted('PERM', 'acces.bloquer_support')",
            processor: AnnulerDeclarationIncidentBadgeProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['declaration_incident:read']],
)]
final class DeclarationIncidentBadge
{
    #[ApiProperty(identifier: true)]
    #[Groups(['declaration_incident:read'])]
    public string $id = '';

    #[Groups(['declaration_incident:read'])]
    public string $badgeStaff = '';

    #[Groups(['declaration_incident:read'])]
    public string $motif = '';

    #[Groups(['declaration_incident:read'])]
    public string $agent = '';

    #[Groups(['declaration_incident:read'])]
    public string $horodatage = '';

    #[Groups(['declaration_incident:read'])]
    public bool $annulee = false;
}
