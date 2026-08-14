<?php

declare(strict_types=1);

namespace App\Acces\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Acces\State\EtatSynchroAccesProvider;
use App\Acces\State\SynchroProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Synchronisation hors-ligne (US-L3-07/08, RG-ACC-05, CA-8/9). N'est pas une entité Doctrine : expose
 * l'état réseau des contrôleurs et le point d'ancrage du rejeu chronologique idempotent.
 */
#[ApiResource(
    shortName: 'SynchronisationAcces',
    operations: [
        new Get(
            uriTemplate: '/acces/synchro/etat',
            security: "is_granted('PERM', 'acces.superviser')",
            provider: EtatSynchroAccesProvider::class,
            normalizationContext: ['groups' => ['synchro_acces:read']],
        ),
        new Post(
            uriTemplate: '/acces/synchro',
            read: false,
            input: false,
            security: "is_granted('PERM', 'acces.ingestion')",
            processor: SynchroProcessor::class,
        ),
    ],
)]
final class SynchronisationAcces
{
    #[ApiProperty(identifier: true)]
    #[Groups(['synchro_acces:read'])]
    public string $id = 'etat';

    /** en_ligne | hors_ligne | mixte */
    #[Groups(['synchro_acces:read'])]
    public string $etat = 'en_ligne';

    /** @var list<array<string, mixed>> */
    #[Groups(['synchro_acces:read'])]
    public array $controleurs = [];
}
