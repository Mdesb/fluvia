<?php

declare(strict_types=1);

namespace App\Dms\Service;

/**
 * Jeton de lien public — opaque, aléatoire, 256 bits d'entropie, **sans HMAC** (déviation assumée vs.
 * la piste non normative RG-DMS-08, plan-dms.md §0.4) : la seule autorité de vérité est
 * l'enregistrement persisté `DocumentPublicLink` (RG-DMS-08 exige qu'il porte la révocation), le
 * contrôleur public interroge toujours la base — pas besoin de vérifiabilité hors ligne comme
 * `App\Vente\Service\GenerateurCodeSupport`. Aucune nouvelle variable d'environnement requise.
 */
final class PublicLinkTokenGenerator
{
    /** Jeton en clair, remis **une seule fois** à l'émission — jamais stocké tel quel. */
    public function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Empreinte stockée en base (`DocumentPublicLink.tokenHash`) — jamais le jeton en clair. */
    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
