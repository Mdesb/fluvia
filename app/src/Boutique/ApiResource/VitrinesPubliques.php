<?php

declare(strict_types=1);

namespace App\Boutique\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Boutique\State\VitrinesPubliquesProvider;

/**
 * Listing public des vitrines publiées (comble des manques boutique) : point d'entrée pour un front
 * public qui ne dispose d'aucun JWT staff (contrairement à `GetCollection /boutique/vitrines`,
 * réservé à `boutique.lire`). Ressource autonome, même raison que `CatalogueVitrine`/`CreneauxProduit`
 * (aucune ambiguïté d'identifiant, uriTemplate distinct pour ne jamais entrer en conflit avec les
 * opérations CRUD de `App\Boutique\Entity\Vitrine`).
 */
#[ApiResource(
    shortName: 'BoutiqueVitrinesPubliques',
    operations: [
        new GetCollection(
            uriTemplate: '/boutique/vitrines-publiques',
            security: "is_granted('PUBLIC_ACCESS')",
            provider: VitrinesPubliquesProvider::class,
        ),
    ],
)]
final class VitrinesPubliques
{
    #[ApiProperty(identifier: true)]
    public string $id = '';
}
