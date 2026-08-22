<?php

declare(strict_types=1);

namespace App\Dms\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Sortie de `POST /documents/{documentId}/public-links` — porte le jeton en clair **une seule fois**
 * (jamais réutilisé en lecture ultérieure : `DocumentPublicLink` ne porte que `tokenHash`). N'est pas
 * une entité Doctrine, même patron que `App\Vente\ApiResource\Synchronisation` (ressource API Platform
 * autonome, sans persistance).
 */
final readonly class PublicLinkIssued
{
    public function __construct(
        #[Groups(['public_link_issued:read'])]
        public string $id,
        #[Groups(['public_link_issued:read'])]
        public string $url,
        #[Groups(['public_link_issued:read'])]
        public string $expiresAt,
        #[Groups(['public_link_issued:read'])]
        public string $versionId,
    ) {
    }
}
