<?php

declare(strict_types=1);

namespace App\Boutique\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Boutique\State\CreneauxProduitProvider;

/**
 * Créneaux disponibles d'un produit timed-entry (US-L8-02, RG-M3-02, CA-2) : ressource autonome,
 * même raison que `CatalogueVitrine` (aucune ambiguïté d'identifiant avec `App\Offre\Entity\Produit`,
 * non modifié par ce lot).
 */
#[ApiResource(
    shortName: 'BoutiqueCreneauxProduit',
    operations: [
        new Get(
            uriTemplate: '/boutique/produits/{id}/creneaux',
            security: "is_granted('PUBLIC_ACCESS')",
            provider: CreneauxProduitProvider::class,
        ),
    ],
)]
final class CreneauxProduit
{
    #[ApiProperty(identifier: true)]
    public string $id = '';
}
