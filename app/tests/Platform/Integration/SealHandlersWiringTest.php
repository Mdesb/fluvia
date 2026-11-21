<?php

declare(strict_types=1);

namespace App\Tests\Platform\Integration;

use App\Compta\Nf525\ScellementEcritureHandler;
use App\Facturation\Nf525\ScellementFactureHandler;
use App\Platform\Nf525\Alert\SealAlertRecorder;
use App\Vente\Nf525\ScellementHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LES TROIS HANDLERS DE SCELLEMENT REÇOIVENT-ILS LE VRAI `SealAlertRecorder` DU CONTENEUR ?
 *
 * ── LE PIÈGE ────────────────────────────────────────────────────────────────────────────────────
 *
 * L'argument est optionnel (`?SealAlertRecorder $alerts = null`) : si l'autowiring ne l'injectait pas, tout
 * serait vert (aucune alerte, aucune erreur) et la production n'alerterait jamais. Ce test lit la propriété
 * du handler tel que le conteneur le construit, et ne repose sur aucune déclaration `when@test`.
 */
final class SealHandlersWiringTest extends KernelTestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function handlers(): iterable
    {
        yield 'vente' => [ScellementHandler::class];
        yield 'compta' => [ScellementEcritureHandler::class];
        yield 'facturation' => [ScellementFactureHandler::class];
    }

    /** @param class-string $class */
    #[\PHPUnit\Framework\Attributes\DataProvider('handlers')]
    public function testTheContainerInjectsTheRealRecorder(string $class): void
    {
        self::bootKernel();
        $handler = static::getContainer()->get($class);
        self::assertInstanceOf($class, $handler);

        $recorder = (new \ReflectionProperty($class, 'alerts'))->getValue($handler);

        self::assertInstanceOf(SealAlertRecorder::class, $recorder);
    }
}
