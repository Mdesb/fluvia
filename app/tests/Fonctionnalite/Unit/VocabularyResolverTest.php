<?php

declare(strict_types=1);

namespace App\Tests\Fonctionnalite\Unit;

use App\Fonctionnalite\Service\VocabularyResolver;
use App\Platform\Module\ModuleManifest;
use App\Platform\Module\ModuleRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Le résolveur de vocabulaire (#100, option B, CP-1 du 14/09).
 *
 * Trois faits : une verticale surcharge le mot ; sans verticale / verticale inconnue / clé non
 * surchargée, on retombe sur le défaut FR ; une clé hors catalogue est rendue telle quelle. On teste
 * la LOGIQUE du résolveur avec un manifeste factice — les vrais mots par verticale sont déjà protégés
 * par `VocabulaireManifesteTest`.
 */
final class VocabularyResolverTest extends TestCase
{
    private function resolver(ModuleManifest ...$manifests): VocabularyResolver
    {
        return new VocabularyResolver(new ModuleRegistry($manifests));
    }

    public function testUneVerticaleSurchargeLaCle(): void
    {
        $r = $this->resolver($this->manifeste('padel', ['slot' => 'Réservation de terrain', 'resource' => 'Terrain']));

        self::assertSame('Réservation de terrain', $r->label('slot', 'padel'));
        self::assertSame('Terrain', $r->label('resource', 'padel'));
    }

    public function testDefautFRSansVerticaleOuInconnueOuCleNonSurchargee(): void
    {
        // padel surcharge 'slot' mais PAS 'deposit'
        $r = $this->resolver($this->manifeste('padel', ['slot' => 'Réservation de terrain']));

        self::assertSame('Créneau', $r->label('slot', null), 'aucune verticale : défaut FR');
        self::assertSame('Créneau', $r->label('slot', 'inexistante'), 'verticale inconnue : défaut FR');
        self::assertSame('Caution', $r->label('deposit', 'padel'), 'clé non surchargée par la verticale : défaut FR');
    }

    public function testUneCleHorsCatalogueEstRendueTelleQuelle(): void
    {
        self::assertSame('inconnue', $this->resolver()->label('inconnue', null));
    }

    public function testLaTableResoutLesDouzeCles(): void
    {
        $table = $this->resolver($this->manifeste('padel', ['slot' => 'Réservation de terrain']))->table('padel');

        self::assertCount(12, $table, 'les douze clés du catalogue');
        self::assertSame('Réservation de terrain', $table['slot'], 'surchargée');
        self::assertSame('Ressource', $table['resource'], 'non surchargée : défaut FR');
    }

    /** @param array<string,string> $vocabulary */
    private function manifeste(string $id, array $vocabulary): ModuleManifest
    {
        return new class($id, $vocabulary) implements ModuleManifest {
            /** @param array<string,string> $vocabulary */
            public function __construct(private readonly string $moduleId, private readonly array $vocabulary)
            {
            }

            public function id(): string
            {
                return $this->moduleId;
            }

            public function version(): string
            {
                return '1.0.0';
            }

            public function capability(): ?string
            {
                return $this->moduleId;
            }

            public function dependencies(): array
            {
                return [];
            }

            public function permissions(): array
            {
                return [];
            }

            public function eventsEmitted(): array
            {
                return [];
            }

            public function eventsConsumed(): array
            {
                return [];
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
                return ['vocabulary' => $this->vocabulary];
            }
        };
    }
}
