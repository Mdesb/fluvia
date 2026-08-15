<?php

declare(strict_types=1);

namespace App\Securite\Security;

use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Invalidation de session/JWT à chaque requête authentifiée (§2.2 plan-backoffice.md, CA-3) : le
 * JWT reste stateless, mais ce listener compare le claim `tokenVersion` du jeton à
 * `Utilisateur::tokenVersion` et vérifie `statut === actif`. Un jeton émis avant une suspension ou
 * une réinitialisation de mot de passe échoue donc dès la requête suivante (401).
 *
 * Sert aussi de point d'entrée pour la restriction du jeton « pré-authentifié » MFA (§2.3) : tant
 * que le claim `mfaEnAttente` est présent, seule la route `/auth/mfa-verifier` reste accessible.
 */
final class VerificateurJwtActifListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Events::JWT_AUTHENTICATED => 'onJwtAuthenticated',
        ];
    }

    public function onJwtAuthenticated(JWTAuthenticatedEvent $event): void
    {
        $token = $event->getToken();
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $payload = $event->getPayload();

        $mfaEnAttente = (bool) ($payload['mfaEnAttente'] ?? false);
        if ($mfaEnAttente) {
            $chemin = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';
            if ($chemin !== '/auth/mfa-verifier') {
                throw new AuthenticationException('Second facteur requis.');
            }

            // Jeton pré-authentifié : n'exige pas le tokenVersion final (émis avant le 2ᵉ facteur).
            return;
        }

        if ($utilisateur->getStatut() !== StatutUtilisateur::Actif) {
            throw new AuthenticationException('Compte non actif.');
        }

        $tokenVersionPayload = (int) ($payload['tokenVersion'] ?? -1);
        if ($tokenVersionPayload !== $utilisateur->getTokenVersion()) {
            throw new AuthenticationException('Session expirée : reconnectez-vous.');
        }
    }
}
