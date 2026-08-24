<?php

declare(strict_types=1);

namespace App\Social\Dto;

/**
 * Ce qu'un réseau rend quand il a accepté (SOC-2).
 *
 * `remotePostId` est la seule preuve que quelque chose est bien paru — c'est aussi la clé par laquelle
 * SOC-3 ira chercher les statistiques. `remoteUrl` est du confort : tous les réseaux ne la rendent pas
 * directement, certains obligent à la reconstruire.
 */
final readonly class PublicationOutcome
{
    public function __construct(
        public string $remotePostId,
        public ?string $remoteUrl = null,
    ) {
    }
}
