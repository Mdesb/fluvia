<?php

declare(strict_types=1);

namespace App\Subscription\Dto;

use App\Subscription\Entity\ProvisioningRequest;

/**
 * Le résultat d'un provisioning, et le seul endroit où le jeton d'invitation existe en clair (ED-3).
 *
 * **Pourquoi ne pas simplement rendre la `ProvisioningRequest`.** Parce que l'administrateur créé
 * doit recevoir un jeton d'activation, que ce jeton n'est stocké qu'en `sha256` (comme partout
 * ailleurs dans `Securite`), et qu'il n'existe donc en clair que le temps d'un appel. Le poser sur
 * l'entité le rendrait persistable par distraction ; le poser ici le rend impossible à écrire.
 *
 * **`invitationToken` est nul sur un rejeu.** Un provisioning déjà abouti ne régénère pas de jeton :
 * cela invaliderait celui qu'on vient d'envoyer au client, et un webhook répété suffirait à couper
 * l'accès d'un administrateur légitime.
 */
final readonly class ProvisioningOutcome
{
    public function __construct(
        public ProvisioningRequest $request,
        public ?string $invitationToken = null,
    ) {
    }

    /** Vrai si cet appel a réellement créé l'établissement, faux s'il a retrouvé un provisioning existant. */
    public function isFirstRun(): bool
    {
        return null !== $this->invitationToken;
    }
}
