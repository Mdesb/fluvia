<?php

declare(strict_types=1);

namespace App\PublicApi\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * La limite de débit de `/v1`, PAR CLÉ : 120 requêtes par minute (spec API partenaire v1, §3.1).
 *
 * ⚠ **LE COMPTEUR VIT EN BASE, ET C'EST TOUTE LA RÈGLE.** Le limiteur `public_api` (framework.yaml) range
 * ses fenêtres dans le pool `cache.public_api_rate_limit` (adaptateur DBAL, table `cache_items`) et
 * sérialise la lecture-écriture par un verrou `DoctrineDbalStore` (table `lock_keys`). Un compteur en
 * APCu ou en fichier serait compté PAR PROCESSUS php-fpm : avec dix processus, la limite annoncée de 120
 * vaudrait 1 200, et rien ne le montrerait.
 *
 * Il passe juste après le pare-feu (priorité 8) : la clé est déjà authentifiée, une clé inconnue a
 * déjà reçu son 401 — on ne compte que ce qui a le droit d'être compté.
 *
 * En-têtes (brouillon IETF « RateLimit header fields ») : `RateLimit-Limit`, `RateLimit-Remaining`,
 * `RateLimit-Reset` = secondes avant qu'une requête soit de nouveau acceptée (0 tant qu'il en reste),
 * et `Retry-After` sur le 429.
 */
final class PublicApiRateLimiter implements EventSubscriberInterface
{
    private const ATTRIBUTE = '_public_api_rate_limit';

    public function __construct(
        #[Autowire(service: 'limiter.public_api')]
        private readonly RateLimiterFactoryInterface $limiter,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 4],
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $partner = $this->security->getUser();
        if (!$partner instanceof PartnerUser) {
            return;
        }

        $limit = $this->limiter->create($partner->credentialId)->consume();
        $event->getRequest()->attributes->set(self::ATTRIBUTE, $limit);

        if (!$limit->isAccepted()) {
            $event->setResponse(new JsonResponse(
                ['message' => sprintf('Limite de %d requêtes par minute atteinte pour cette clé. Réessayez dans %d s.', $limit->getLimit(), $this->secondsToWait($limit))],
                JsonResponse::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) max(1, $this->secondsToWait($limit))],
            ));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $limit = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (!$limit instanceof RateLimit) {
            return;
        }

        $event->getResponse()->headers->add([
            'RateLimit-Limit' => (string) $limit->getLimit(),
            'RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
            'RateLimit-Reset' => (string) $this->secondsToWait($limit),
        ]);
    }

    private function secondsToWait(RateLimit $limit): int
    {
        return max(0, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
