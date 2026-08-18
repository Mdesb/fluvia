<?php

declare(strict_types=1);

namespace App\Boutique\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Anti-bruteforce sur `POST /boutique/paniers/{id}/identifier` (mode `compte`, revue de sécurité —
 * faille majeure) : ce point d'entrée valide un mot de passe **hors firewall** (`security.yaml` ne
 * couvre pas cette route applicative publique — aucun throttling `login_throttling` natif ne
 * s'applique ici).
 *
 * ⚠ Le composant `symfony/rate-limiter` n'est **pas installé** dans ce dépôt (absent de
 * `composer.json`/`vendor/symfony`) — conformément à la consigne du correctif (rester ciblé, ne pas
 * ajouter de dépendance composer pour ce lot), ce garde retombe volontairement sur un **compteur
 * applicatif simple** appuyé sur le pool `cache.app` déjà configuré (`config/packages/cache.yaml`) :
 * clé = sha256(ip + email), fenêtre glissante approximée par un TTL (comportement fenêtre fixe, pas
 * un vrai sliding window). À remplacer par `Symfony\Component\RateLimiter\RateLimiterFactory` si le
 * composant est installé ultérieurement (même seuil : 5 échecs / 15 min).
 */
final class TentativeIdentificationLimiter
{
    private const MAX_TENTATIVES = 5;
    private const FENETRE_SECONDES = 900;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @throws TooManyRequestsHttpException si le quota d'échecs est déjà atteint pour ip+email. */
    public function verifierAvantTentative(string $email): void
    {
        $item = $this->cache->getItem($this->cle($email));
        $tentatives = (int) ($item->isHit() ? $item->get() : 0);
        if ($tentatives >= self::MAX_TENTATIVES) {
            throw new TooManyRequestsHttpException(
                self::FENETRE_SECONDES,
                'Trop de tentatives d\'identification échouées, réessayez plus tard.',
            );
        }
    }

    public function enregistrerEchec(string $email): void
    {
        $item = $this->cache->getItem($this->cle($email));
        $tentatives = (int) ($item->isHit() ? $item->get() : 0);
        $item->set($tentatives + 1);
        $item->expiresAfter(self::FENETRE_SECONDES);
        $this->cache->save($item);
    }

    public function reinitialiser(string $email): void
    {
        $this->cache->deleteItem($this->cle($email));
    }

    private function cle(string $email): string
    {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'inconnu';

        return 'bou_ident_' . hash('sha256', $ip . '|' . mb_strtolower($email));
    }
}
