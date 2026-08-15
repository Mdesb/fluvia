<?php

declare(strict_types=1);

namespace App\Piscine\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Piscine\State\InstancierModelePiscineTypeProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * POST /piscine/modeles/piscine-type/instancier (US-L6-01, CA-1) : instancie en un clic le
 * catalogue « piscine type » (entrées adulte/enfant, carte 10=12, abonnements Gold/Classique, cours)
 * via le module Offre (M1). N'est pas une entité Doctrine.
 */
#[ApiResource(
    shortName: 'ModelePiscineType',
    operations: [
        new Post(
            uriTemplate: '/piscine/modeles/piscine-type/instancier',
            read: false,
            input: false,
            security: "is_granted('PERM', 'piscine.configurer') and is_granted('PERM', 'offre.creer')",
            processor: InstancierModelePiscineTypeProcessor::class,
            normalizationContext: ['groups' => ['modele:read']],
        ),
    ],
)]
final class ModelePiscineType
{
    #[ApiProperty(identifier: true)]
    #[Groups(['modele:read'])]
    public string $id = 'piscine-type';

    /** @var list<string> IRI des produits créés (les produits déjà existants, mêmes codes, ne sont pas dupliqués). */
    #[Groups(['modele:read'])]
    public array $produitsCrees = [];
}
