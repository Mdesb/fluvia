<?php

declare(strict_types=1);

namespace App\Boutique\Identite;

/**
 * Port d'identité FranceConnect (§4.4 spec-boutique.md, US-L8-04, RG-M3-06). ⚠ HYPOTHÈSE — protocole
 * OIDC exact, scope, gestion du callback/erreur non détaillés par les sources (Risque n°4 du plan).
 */
interface FournisseurIdentiteInterface
{
    public function urlAutorisation(string $redirectUri): string;

    /** Échange le code de retour OIDC contre l'identité ; lève une exception applicative si invalide/expiré. */
    public function authentifier(string $code, string $redirectUri): IdentiteFranceConnect;
}
