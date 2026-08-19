<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Platform\Event\Exception\EventBusOverflowException;
use App\Platform\Event\SymfonyEventBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Uuid;

/**
 * Réentrance bornée (spec §7).
 *
 * Le bus étant synchrone, un abonné qui republie un fait qui le redéclenche boucle jusqu'à épuiser la
 * pile PHP. Le garde-fou ne rend pas la boucle correcte — il la rend **diagnosticable** : l'erreur
 * nomme la chaîne d'événements, là où un dépassement de pile ne donne rien d'exploitable.
 */
final class EventBusDepthTest extends TestCase
{
    private function event(string $name): DomainEvent
    {
        return new DomainEvent(
            $name,
            new EventTenant(Uuid::v4()),
            new EventSubject('Invoice', Uuid::v4()->toRfc4122()),
        );
    }

    public function testBoucleDirecteInterrompue(): void
    {
        $dispatcher = new EventDispatcher();
        $bus = new SymfonyEventBus($dispatcher, maxDepth: 3);

        $dispatcher->addListener('invoice.issued', function () use ($bus): void {
            $bus->publish($this->event('invoice.issued'));
        });

        $this->expectException(EventBusOverflowException::class);
        $this->expectExceptionMessageMatches('/Profondeur de publication dépassée \(3\)/');

        $bus->publish($this->event('invoice.issued'));
    }

    /** Le message doit permettre de retrouver le cycle, pas seulement de constater l'échec. */
    public function testLeMessageNommeLaChaine(): void
    {
        $dispatcher = new EventDispatcher();
        $bus = new SymfonyEventBus($dispatcher, maxDepth: 2);

        $dispatcher->addListener('invoice.issued', function () use ($bus): void {
            $bus->publish($this->event('invoice.paid'));
        });
        $dispatcher->addListener('invoice.paid', function () use ($bus): void {
            $bus->publish($this->event('invoice.issued'));
        });

        try {
            $bus->publish($this->event('invoice.issued'));
            self::fail('Une boucle indirecte aurait dû être interrompue.');
        } catch (EventBusOverflowException $e) {
            self::assertStringContainsString('invoice.issued → invoice.paid', $e->getMessage());
        }
    }

    /** Une chaîne légitime et courte ne doit rien déclencher. */
    public function testChaineCourteAutorisee(): void
    {
        $dispatcher = new EventDispatcher();
        $bus = new SymfonyEventBus($dispatcher, maxDepth: 3);
        $vus = [];

        $dispatcher->addListener('booking.no_show', function () use ($bus, &$vus): void {
            $vus[] = 'no_show';
            $bus->publish($this->event('slot.released'));
        });
        $dispatcher->addListener('slot.released', static function () use (&$vus): void {
            $vus[] = 'released';
        });

        $bus->publish($this->event('booking.no_show'));

        self::assertSame(['no_show', 'released'], $vus);
    }

    /**
     * Le compteur doit être rétabli même quand un abonné a levé une exception.
     *
     * Sans le `finally`, une seule exception laisserait le bus « profond » pour le reste de la requête
     * et ferait échouer des publications parfaitement légitimes — un bug qui ne se serait manifesté
     * qu'en production, loin de sa cause.
     */
    public function testLaProfondeurEstRetablieApresUneException(): void
    {
        $dispatcher = new EventDispatcher();
        $bus = new SymfonyEventBus($dispatcher, maxDepth: 2);
        $recus = 0;

        $dispatcher->addListener('invoice.issued', static function (): void {
            throw new \DomainException('abonné en échec');
        });
        $dispatcher->addListener('invoice.paid', static function () use (&$recus): void {
            ++$recus;
        });

        for ($i = 0; $i < 5; ++$i) {
            try {
                $bus->publish($this->event('invoice.issued'));
            } catch (\DomainException) {
                // attendu — on vérifie seulement que le bus reste utilisable ensuite
            }
        }

        $bus->publish($this->event('invoice.paid'));

        self::assertSame(1, $recus);
    }
}
