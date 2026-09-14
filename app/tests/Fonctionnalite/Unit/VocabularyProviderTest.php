<?php

declare(strict_types=1);

namespace App\Tests\Fonctionnalite\Unit;

use App\Fonctionnalite\ApiResource\Vocabulary;
use App\Fonctionnalite\Service\VocabularyResolver;
use App\Fonctionnalite\State\VocabularyProvider;
use App\Platform\Module\ModuleManifest;
use App\Platform\Module\ModuleRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Les trois décisions du provider de vocabulaire (#100), testées à nu — `ContexteEtablissement` et
 * `Fonctionnalites` étant `final`, `provide()` n'est qu'un câblage mince par-dessus ces méthodes.
 */
final class VocabularyProviderTest extends TestCase
{
    public function testVerticalesAvecVocabulaireSontLesModulesQuiEnDeclarent(): void
    {
        $registry = new ModuleRegistry([
            $this->manifeste('piscine', ['resource' => 'Bassin']),
            $this->manifeste('padel', ['resource' => 'Terrain']),
            $this->manifeste('compta', []), // aucun vocabulaire : pas une verticale
        ]);

        self::assertSame(['padel', 'piscine'], VocabularyProvider::verticalesAvecVocabulaire($registry), 'triées, sans compta');
    }

    public function testVerticaleUniqueSelonLesCapacitesActives(): void
    {
        self::assertSame('piscine', VocabularyProvider::verticaleUnique(['caisse', 'piscine'], ['piscine', 'padel']), 'une seule verticale active');
        self::assertNull(VocabularyProvider::verticaleUnique(['piscine', 'padel'], ['piscine', 'padel']), 'deux verticales actives : ambigu');
        self::assertNull(VocabularyProvider::verticaleUnique(['caisse'], ['piscine', 'padel']), 'aucune verticale active');
    }

    public function testAssembleDefautPlusVerticalesEtMarqueLaCourante(): void
    {
        $resolver = new VocabularyResolver(new ModuleRegistry([
            $this->manifeste('piscine', ['resource' => 'Bassin', 'slot' => 'Créneau public']),
        ]));

        $parId = $this->parId(VocabularyProvider::assembler($resolver, ['piscine'], 'piscine'));

        self::assertSame('Bassin', $parId['piscine']->labels['resource'], 'surchargée par la verticale');
        self::assertSame('Ressource', $parId['default']->labels['resource'], 'défaut FR');
        self::assertTrue($parId['piscine']->courant);
        self::assertFalse($parId['default']->courant);
    }

    public function testSansVerticaleUnique_leDefautPorteLeRepli(): void
    {
        $resolver = new VocabularyResolver(new ModuleRegistry([$this->manifeste('piscine', ['resource' => 'Bassin'])]));

        $parId = $this->parId(VocabularyProvider::assembler($resolver, ['piscine'], null));

        self::assertTrue($parId['default']->courant, 'aucune verticale unique : le défaut est le repli');
        self::assertFalse($parId['piscine']->courant);
    }

    /**
     * @param list<Vocabulary> $entrees
     *
     * @return array<string, Vocabulary>
     */
    private function parId(array $entrees): array
    {
        $parId = [];
        foreach ($entrees as $e) {
            $parId[$e->id] = $e;
        }

        return $parId;
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
