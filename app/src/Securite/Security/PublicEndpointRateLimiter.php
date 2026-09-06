<?php

declare(strict_types=1);

namespace App\Securite\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Borne les deux portes PUBLIQUES qui écrivent un compte ou touchent à un mot de passe — audit du 06/09,
 * constat 7.
 *
 *  - `POST /boutique/comptes` : n'importe qui crée un `Utilisateur` actif, sans rien prouver. Sans borne,
 *    une boucle en verse des milliers, et chacun est un jeton valide (constat 4).
 *  - `POST /mot-de-passe/oublie` : un jeton par appel, un courriel par appel le jour où l'expéditeur
 *    sera branché — et une adresse qu'on peut faire viser cent fois.
 *
 * Les limiteurs eux-mêmes sont déclarés dans `framework.yaml` (`rate_limiter:`), là où vit aussi celui de
 * la connexion (`login_throttling`, `security.yaml`) : à partir du 06/09 le noyau en déclare, et ce
 * service se contente de les injecter — c'est la sortie que `CartRateLimiter` s'était promise.
 *
 * ⚠ LA CLÉ EST L'ADRESSE, DONC L'ADRESSE DOIT ÊTRE VRAIE. Derrière nginx, sans `trusted_proxies`, toutes
 * les requêtes portent l'adresse du mandataire et une seule borne vaut pour tout le monde — c'est ce que
 * l'audit relevait au constat 11, corrigé dans `framework.yaml` en même temps que ceci. Adresse inconnue :
 * on borne quand même, sur une clé commune ; laisser passer faute d'adresse donnerait la marche à suivre.
 */
final class PublicEndpointRateLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.public_account_creation')]
        private readonly RateLimiterFactory $accountCreation,
        #[Autowire(service: 'limiter.password_reset_request')]
        private readonly RateLimiterFactory $passwordResetRequest,
    ) {
    }

    /** @throws TooManyRequestsHttpException */
    public function assertAccountCreationAllowed(?string $clientIp): void
    {
        $this->consume($this->accountCreation, $clientIp, 'Trop de créations de compte depuis cette adresse. Réessayez plus tard.');
    }

    /** @throws TooManyRequestsHttpException */
    public function assertPasswordResetAllowed(?string $clientIp): void
    {
        $this->consume($this->passwordResetRequest, $clientIp, 'Trop de demandes de réinitialisation depuis cette adresse. Réessayez plus tard.');
    }

    private function consume(RateLimiterFactory $factory, ?string $clientIp, string $message): void
    {
        $limit = $factory->create($clientIp ?? 'inconnue')->consume();
        if ($limit->isAccepted()) {
            return;
        }

        throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), $message);
    }
}
