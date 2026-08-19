<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Platform\Event\SymfonyEventBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Uuid;

/**
 * CA-1 — un module publie, un autre reçoit, **sans que les deux se connaissent**.
 *
 * Le test est écrit pour rendre le découplage visible : l'abonné s'enregistre sur la **chaîne**
 * `supplier_invoice.recorded` (le contrat), et à aucun moment il ne référence une classe appartenant à
 * l'émetteur. C'est tout l'objet du lot ; si un jour ce test doit importer du code de Finance pour
 * s'abonner, c'est que la conception a régressé.
 */
final class SymfonyEventBusTest extends TestCase
{
    private function event(string $name = 'supplier_invoice.recorded', array $payload = []): DomainEvent
    {
        return new DomainEvent(
            $name,
            new EventTenant(Uuid::v4()),
            new EventSubject('SupplierInvoice', Uuid::v4()->toRfc4122()),
            $payload,
        );
    }

    public function testCa1LAbonneRecoitLEnveloppe(): void
    {
        $dispatcher = new EventDispatcher();
        $bus = new SymfonyEventBus($dispatcher);
        $recus = [];

        $dispatcher->addListener(
            'supplier_invoice.recorded',
            static function (DomainEvent $event) use (&$recus): void {
                $recus[] = $event;
            },
        );

        $bus->publish($this->event(payload: ['supplier' => 'ACME', 'amount' => 990]));

        self::assertCount(1, $recus);
        self::assertSame('supplier_invoice.recorded', $recus[0]->name->value);
        self::assertSame('ACME', $recus[0]->payload['supplier']);
    }

    public function testUnEvenementNonEcouteNeCassePas(): void
    {
        $bus = new SymfonyEventBus(new EventDispatcher());

        $bus->publish($this->event('quote.expired'));

        $this->expectNotToPerformAssertions();
    }

    public function testSeulsLesAbonnesDuNomSontAppeles(): void
    {
        $dispatcher = new EventDispatcher();
        $bus = new SymfonyEventBus($dispatcher);
        $appels = ['recorded' => 0, 'overdue' => 0];

        $dispatcher->addListener('supplier_invoice.recorded', static function () use (&$appels): void {
            ++$appels['recorded'];
        });
        $dispatcher->addListener('invoice.overdue', static function () use (&$appels): void {
            ++$appels['overdue'];
        });

        $bus->publish($this->event());

        self::assertSame(['recorded' => 1, 'overdue' => 0], $appels);
    }

    /** Deux abonnés au même fait : les deux passent, sans qu'aucun ne dépende de l'autre (spec §7). */
    public function testDeuxAbonnesAuMemeEvenement(): void
    {
        $dispatcher = new EventDispatcher();
        $bus = new SymfonyEventBus($dispatcher);
        $vus = [];

        $dispatcher->addListener('sale.completed', static function () use (&$vus): void {
            $vus[] = 'reporting';
        });
        $dispatcher->addListener('sale.completed', static function () use (&$vus): void {
            $vus[] = 'revenue_recovery';
        });

        $bus->publish($this->event('sale.completed'));

        self::assertCount(2, $vus);
    }

    /**
     * RG-PLAT-05 — l'exception d'un abonné remonte à l'émetteur.
     *
     * C'est un arbitrage assumé (cohérence > disponibilité) : le bus est synchrone, donc dans la
     * transaction de l'émetteur. Un abonné best-effort doit attraper ses propres erreurs.
     */
    public function testRgPlat05LExceptionDUnAbonneRemonte(): void
    {
        $dispatcher = new EventDispatcher();
        $bus = new SymfonyEventBus($dispatcher);

        $dispatcher->addListener('invoice.issued', static function (): void {
            throw new \DomainException('abonné en échec');
        });

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('abonné en échec');

        $bus->publish($this->event('invoice.issued'));
    }
}
