<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Platform\Module\ModuleManifest;
use App\Platform\Module\ModuleRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Le registre (spec C5, CA-6). L'enjeu de ces tests est la **qualité du diagnostic** : un graphe de
 * modules incohérent doit être refusé au démarrage avec un message qui nomme les modules fautifs, pas
 * produire des activations partielles silencieuses qu'on découvrirait en préprod.
 */
final class ModuleRegistryTest extends TestCase
{
    /**
     * @param list<string> $dependencies
     * @param list<string> $emitted
     * @param list<string> $consumed
     */
    private function manifest(
        string $id,
        array $dependencies = [],
        array $emitted = [],
        array $consumed = [],
    ): ModuleManifest {
        return new class($id, $dependencies, $emitted, $consumed) implements ModuleManifest {
            /**
             * @param list<string> $dependencies
             * @param list<string> $emitted
             * @param list<string> $consumed
             */
            public function __construct(
                private readonly string $id,
                private readonly array $dependencies,
                private readonly array $emitted,
                private readonly array $consumed,
            ) {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function version(): string
            {
                return '0.1.0';
            }

            public function capability(): string
            {
                return $this->id;
            }

            public function dependencies(): array
            {
                return $this->dependencies;
            }

            public function permissions(): array
            {
                return [];
            }

            public function eventsEmitted(): array
            {
                return $this->emitted;
            }

            public function eventsConsumed(): array
            {
                return $this->consumed;
            }

            public function features(): array
            {
                return [];
            }

            public function routes(): array
            {
                return [];
            }

            public function settingsSchema(): array
            {
                return [];
            }
        };
    }

    public function testRegistreVideEstValide(): void
    {
        $registry = new ModuleRegistry([]);

        self::assertSame([], $registry->all());
        self::assertFalse($registry->has('finance'));
    }

    public function testIndexationParIdentifiant(): void
    {
        $registry = new ModuleRegistry([$this->manifest('finance'), $this->manifest('ocr')]);

        self::assertTrue($registry->has('finance'));
        self::assertSame('ocr', $registry->get('ocr')->id());
        self::assertSame(['finance', 'ocr'], array_keys($registry->all()));
    }

    public function testModuleInconnuLeveUneErreurQuiListeCeQuiExiste(): void
    {
        $registry = new ModuleRegistry([$this->manifest('finance')]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/finance/');

        $registry->get('treasury');
    }

    public function testIdentifiantDuplique(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/« finance »/');

        new ModuleRegistry([$this->manifest('finance'), $this->manifest('finance')]);
    }

    /**
     * CA-6 — une dépendance vers un module absent est la panne silencieuse typique : le module semble
     * installé, mais la moitié de ce dont il a besoin n'existe pas.
     */
    public function testDependanceInconnueRefusee(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/dépend de « accounting »/');

        new ModuleRegistry([$this->manifest('finance', ['accounting'])]);
    }

    public function testCycleDetecteEtNomme(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Cycle de dépendances/');

        new ModuleRegistry([
            $this->manifest('a', ['b']),
            $this->manifest('b', ['c']),
            $this->manifest('c', ['a']),
        ]);
    }

    public function testAutoDependanceDetectee(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Cycle de dépendances/');

        new ModuleRegistry([$this->manifest('finance', ['finance'])]);
    }

    public function testGrapheAcycliqueProfondAccepte(): void
    {
        $registry = new ModuleRegistry([
            $this->manifest('a', ['b', 'c']),
            $this->manifest('b', ['d']),
            $this->manifest('c', ['d']),
            $this->manifest('d'),
        ]);

        self::assertCount(4, $registry->all());
    }

    /**
     * Sert la revue d'impact : avant de changer la charge utile d'un événement, savoir qui l'écoute.
     */
    public function testConsommateursEtEmetteursDunEvenement(): void
    {
        $registry = new ModuleRegistry([
            $this->manifest('finance', [], ['supplier_invoice.recorded'], ['payment.failed']),
            $this->manifest('recovery', [], [], ['payment.failed']),
            $this->manifest('accounting', [], [], []),
        ]);

        $consommateurs = array_map(
            static fn (ModuleManifest $m): string => $m->id(),
            $registry->consumersOf('payment.failed')
        );
        $emetteurs = array_map(
            static fn (ModuleManifest $m): string => $m->id(),
            $registry->emittersOf('supplier_invoice.recorded')
        );

        self::assertSame(['finance', 'recovery'], $consommateurs);
        self::assertSame(['finance'], $emetteurs);
    }
}
