<?php

declare(strict_types=1);

namespace App\Boutique\Security;

use App\Boutique\Entity\CompteClient;
use App\Boutique\Entity\PanierEnLigne;
use App\Securite\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Contrôle impératif de propriété du panier pour un visiteur anonyme (§4 du plan, ⚠ Risque n°3) :
 * un invité prouve la possession du panier via le jeton `X-Panier-Token` (haché, comparé à
 * `SessionClient.token`) ; un titulaire de compte identifié (JWT) prouve la possession via
 * `PanierEnLigne.compteClient`. Même esprit que les contrôles impératifs déjà pratiqués par
 * `FicheClient360Provider`/`PmvProvider` (code réel) lorsque le voter déclaratif n'est pas exploitable.
 */
final class PanierProprietaireGuard
{
    public const HEADER = 'X-Panier-Token';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Security $security,
    ) {
    }

    public function verifier(PanierEnLigne $panier): void
    {
        $utilisateur = $this->security->getUser();
        if ($utilisateur instanceof Utilisateur) {
            $compte = $panier->getCompteClient();
            if ($compte instanceof CompteClient && $compte->estCelui($utilisateur)) {
                return;
            }
        }

        $token = $this->requestStack->getCurrentRequest()?->headers->get(self::HEADER);
        $session = $panier->getSessionClient();
        if ($token !== null && $session !== null && hash_equals($session->getToken(), hash('sha256', $token))) {
            return;
        }

        throw new AccessDeniedHttpException('Jeton de panier absent ou invalide (X-Panier-Token).');
    }

    /** Jeton en clair déterministe stocké haché (sha256) — jamais réversible depuis la base. */
    public static function genererJeton(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function hacher(string $jetonClair): string
    {
        return hash('sha256', $jetonClair);
    }

    public static function estUuid(mixed $valeur): ?Uuid
    {
        if (!\is_string($valeur) || $valeur === '') {
            return null;
        }
        $segment = str_contains($valeur, '/') ? basename($valeur) : $valeur;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
