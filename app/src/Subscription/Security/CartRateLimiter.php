<?php

declare(strict_types=1);

namespace App\Subscription\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Borne l'ouverture de panier depuis une adresse anonyme (ED-5).
 *
 * **Pourquoi ce garde existe.** `POST /editor/carts` est ouvert en `PUBLIC_ACCESS` — un prospect n'a
 * pas de compte, c'est le point de départ de la vente. Mais il **écrit** : chaque appel crée une fiche
 * client dans le CRM de l'éditeur. Sans borne, une boucle triviale y verse des milliers de prospects
 * fantômes, et le jour où cela arrive on ne sait plus distinguer l'abus d'une campagne qui marche.
 *
 * **Fenêtre glissante et non compteur par heure.** Un compteur remis à zéro à heure fixe autorise deux
 * fois la limite à cheval sur la bascule ; la fenêtre glissante ne le permet pas. Le surcoût est nul
 * ici, la subtilité seulement au moment de choisir.
 *
 * **La limite est volontairement basse.** Cinq paniers par heure et par adresse : personne ne compose
 * cinq souscriptions sérieuses en une heure, et un prospect qui recommence deux ou trois fois — parce
 * qu'il hésite, ou parce qu'il s'est trompé — passe sans s'en apercevoir.
 *
 * **La fabrique est construite ici plutôt que déclarée dans `framework.yaml`.** Le composant est
 * installé mais aucun limiteur n'y est configuré, et ce fichier appartient au noyau, pas à ce
 * périmètre. La configuration vit donc à côté de la règle qu'elle sert, ce qui a un avantage propre :
 * on lit la limite dans le même fichier que la raison de la limite. Si le noyau en déclare un un
 * jour, ce service se réduira à l'injecter.
 */
final class CartRateLimiter
{
    /** Paniers ouverts par adresse et par fenêtre. */
    private const LIMIT = 5;

    private const INTERVAL = '1 hour';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $pool,
    ) {
    }

    /**
     * @throws TooManyRequestsHttpException si l'adresse a dépassé sa part
     */
    public function assertNotExceeded(?string $clientIp): void
    {
        // Adresse inconnue : on borne quand même, sur une clé commune. Laisser passer une requête
        // parce qu'on ne sait pas d'où elle vient donnerait la marche à suivre pour contourner la
        // borne — il suffirait de masquer l'adresse.
        $limiter = $this->factory()->create($clientIp ?? 'inconnue');

        $limite = $limiter->consume();

        if (!$limite->isAccepted()) {
            throw new TooManyRequestsHttpException(
                max(1, $limite->getRetryAfter()->getTimestamp() - time()),
                'Trop de demandes depuis cette adresse. Réessayez dans quelques minutes, '
                .'ou écrivez-nous : nous composons l\'offre avec vous.',
            );
        }
    }

    private function factory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            [
                'id' => 'subscription_cart',
                'policy' => 'sliding_window',
                'limit' => self::LIMIT,
                'interval' => self::INTERVAL,
            ],
            new CacheStorage($this->pool),
        );
    }
}
