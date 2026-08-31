<?php

declare(strict_types=1);

namespace App\Tests\Verticales;

use App\Musee\MuseeModule;
use App\Padel\PadelModule;
use App\Patinoire\PatinoireModule;
use App\Piscine\PiscineModule;
use App\Platform\Module\ModuleManifest;
use App\Sport\SportModule;
use PHPUnit\Framework\TestCase;

/**
 * Les cinq verticales déclarent leur vocabulaire au manifeste (D15).
 *
 * **Ce que ce test protège.** Le vocabulaire est ce qui rend le logiciel lisible par un métier qui
 * n'est pas celui pour lequel on l'a écrit : un « créneau » est un *rendez-vous* chez le coiffeur et
 * une *réservation de terrain* au padel. Tant qu'il vivait dans un tableau de documentation, rien
 * n'empêchait une verticale d'être livrée sans, ni un renommage d'en inventer un hors catalogue.
 *
 * Deux invariants, et pas un de plus — un test de vocabulaire qui figerait les mots eux-mêmes
 * empêcherait l'exploitant de les changer, ce que D15 exige justement de permettre :
 *
 * 1. **chaque verticale déclare au moins la ressource, le créneau et le participant** — les trois
 *    concepts que tout écran générique affiche, donc les trois qui se voient tout de suite ;
 *    ce sont aussi ceux qui n'ont de sens dans aucun métier sous leur nom générique ;
 * 2. **aucune clé hors catalogue** — une verticale qui invente `court_surface` se coupe du résolveur
 *    commun sans que rien ne le signale, et le mot n'apparaîtrait jamais nulle part.
 */
final class VocabulaireManifesteTest extends TestCase
{
    /** Le catalogue commun (specs/verticales/vocabulaire.md). Une clé absente d'ici n'est résolue par personne. */
    private const CATALOGUE = [
        'resource', 'resource_unit', 'slot', 'booking', 'participant', 'staff', 'group',
        'entry', 'multi_entry_card', 'capacity', 'rental', 'deposit',
    ];

    private const OBLIGATOIRES = ['resource', 'slot', 'participant'];

    /** @return iterable<string, array{ModuleManifest}> */
    public static function verticales(): iterable
    {
        yield 'piscine' => [new PiscineModule()];
        yield 'padel' => [new PadelModule()];
        yield 'patinoire' => [new PatinoireModule()];
        yield 'sport' => [new SportModule()];
        yield 'musee' => [new MuseeModule()];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('verticales')]
    public function testChaqueVerticaleDeclareSonVocabulaire(ModuleManifest $manifeste): void
    {
        $schema = $manifeste->settingsSchema();
        self::assertArrayHasKey('vocabulary', $schema, $manifeste->id() . ' : aucun vocabulaire déclaré.');

        $vocabulaire = $schema['vocabulary'];
        self::assertIsArray($vocabulaire);

        foreach (self::OBLIGATOIRES as $cle) {
            self::assertArrayHasKey($cle, $vocabulaire, $manifeste->id() . " : la clé « {$cle} » s'affiche sur les écrans génériques, elle ne peut pas rester au défaut.");
            self::assertNotSame('', trim((string) $vocabulaire[$cle]), $manifeste->id() . " : « {$cle} » est déclarée vide, ce qui n'est ni une surcharge ni un défaut.");
        }

        foreach (array_keys($vocabulaire) as $cle) {
            self::assertContains($cle, self::CATALOGUE, $manifeste->id() . " : « {$cle} » est hors catalogue — aucun résolveur ne la lira.");
        }
    }
}
