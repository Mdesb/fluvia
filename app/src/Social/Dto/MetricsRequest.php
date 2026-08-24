<?php

declare(strict_types=1);

namespace App\Social\Dto;

use App\Social\Enum\SocialNetwork;

/**
 * Ce dont un collecteur a besoin pour aller chercher les statistiques d'une publication (SOC-3).
 *
 * Même règle que `PublicationRequest` : aucune entité Doctrine ne traverse le port. Un collecteur qui
 * recevrait la publication pourrait remonter jusqu'aux jetons des autres comptes.
 */
final readonly class MetricsRequest
{
    public function __construct(
        public SocialNetwork $network,
        public ?string $host,
        public string $accessToken,
        public string $handle,
        /** Identifiant rendu par le réseau à la publication — la clé de la jointure. */
        public string $remotePostId,
    ) {
    }
}
