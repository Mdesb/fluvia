<?php

declare(strict_types=1);

namespace App\Social\Dto;

use App\Social\Enum\SocialNetwork;

/**
 * Ce dont un adaptateur a besoin pour publier, et rien de plus (SOC-2).
 *
 * Volontairement dépourvu de toute entité Doctrine : un adaptateur qui recevrait un `SocialAccount`
 * pourrait remonter jusqu'à l'établissement, jusqu'aux autres comptes, et lire un jeton qui n'est pas
 * le sien. Ce qu'on ne lui donne pas, il ne peut pas s'en servir.
 *
 * `idempotencyKey` est l'identifiant de la publication : les reprises sont la règle, pas l'exception,
 * et un réseau qui sait dédupliquer doit pouvoir le faire.
 */
final readonly class PublicationRequest
{
    public function __construct(
        public SocialNetwork $network,
        public ?string $host,
        /** Jeton déjà déchiffré — il ne quitte jamais le processus, et n'est jamais journalisé. */
        public string $accessToken,
        /** Identifiant du compte chez le réseau (le `did` chez Bluesky). */
        public string $remoteAccountId,
        public string $handle,
        public string $body,
        public string $idempotencyKey,
    ) {
    }
}
