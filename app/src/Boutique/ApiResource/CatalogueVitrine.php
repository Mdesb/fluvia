<?php

declare(strict_types=1);

namespace App\Boutique\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Boutique\State\CatalogueVitrineProvider;

/**
 * Catalogue public d'une vitrine (US-L8-01, RG-M3-01/08, CA-1) : ressource autonome (patron
 * `App\Musee\ApiResource\SalleEtatLive`, code réel) pour éviter toute ambiguïté d'identifiant avec
 * les opérations CRUD de `App\Boutique\Entity\Vitrine`. Le provider renvoie directement une
 * `JsonResponse` (agrégation catalogue), cette classe ne sert que d'ancre de routage.
 */
#[ApiResource(
    shortName: 'BoutiqueCatalogueVitrine',
    operations: [
        new Get(
            uriTemplate: '/boutique/vitrines/{id}/catalogue',
            security: "is_granted('PUBLIC_ACCESS')",
            provider: CatalogueVitrineProvider::class,
        ),
    ],
)]
final class CatalogueVitrine
{
    #[ApiProperty(identifier: true)]
    public string $id = '';
}
