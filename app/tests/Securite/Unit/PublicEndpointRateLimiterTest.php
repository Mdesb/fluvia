<?php

declare(strict_types=1);

namespace App\Tests\Securite\Unit;

use App\Securite\Security\PublicEndpointRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Le filet se prouve sur un stockage EN MÉMOIRE, avec une limite de deux : le compteur réel vit dans
 * `cache.app` et survit d'un test à l'autre, ce qui rend toute preuve par l'API dépendante de ce que les
 * tests précédents ont consommé. Ici la troisième demande tombe, et on le voit tomber.
 */
final class PublicEndpointRateLimiterTest extends TestCase
{
    public function testLaTroisiemeCreationDeCompteDepuisLaMemeAdresseEstRefusee(): void
    {
        $limiter = $this->limiter(limit: 2);

        $limiter->assertAccountCreationAllowed('203.0.113.7');
        $limiter->assertAccountCreationAllowed('203.0.113.7');

        $this->expectException(TooManyRequestsHttpException::class);
        $limiter->assertAccountCreationAllowed('203.0.113.7');
    }

    /** Les adresses ne se partagent pas leur part : c'est tout l'objet du `trusted_proxies` du noyau. */
    public function testUneAutreAdresseGardeSaPropreParte(): void
    {
        $limiter = $this->limiter(limit: 2);

        $limiter->assertAccountCreationAllowed('203.0.113.7');
        $limiter->assertAccountCreationAllowed('203.0.113.7');
        $limiter->assertAccountCreationAllowed('203.0.113.8');

        $this->addToAssertionCount(1); // arrivé ici sans exception : la seconde adresse n'est pas bornée par la première
    }

    /** Adresse inconnue : bornée quand même, sur une clé commune — laisser passer donnerait la marche à suivre. */
    public function testUneAdresseInconnueEstBorneeAussi(): void
    {
        $limiter = $this->limiter(limit: 1);

        $limiter->assertPasswordResetAllowed(null);

        $this->expectException(TooManyRequestsHttpException::class);
        $limiter->assertPasswordResetAllowed(null);
    }

    private function limiter(int $limit): PublicEndpointRateLimiter
    {
        $factory = static fn (string $id): RateLimiterFactory => new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );

        return new PublicEndpointRateLimiter($factory('creation'), $factory('reinitialisation'));
    }
}
