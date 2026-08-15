<?php

declare(strict_types=1);

namespace App\Tests\Fonctionnalite\Unit;

use App\Fonctionnalite\Config\PresetVerticale;
use App\Fonctionnalite\Enum\Metier;
use App\Fonctionnalite\Service\CatalogueCapacites;
use PHPUnit\Framework\TestCase;

/**
 * Presets par verticale (spec §« PresetVerticale ») : chaque métier active un jeu non vide de
 * capacités, toutes connues du catalogue.
 */
final class PresetVerticaleTest extends TestCase
{
    public function testChaqueMetierPossedeUnPresetNonVideDeCapacitesConnuesDuCatalogue(): void
    {
        $catalogue = new CatalogueCapacites();

        foreach (Metier::cases() as $metier) {
            $capacites = PresetVerticale::capacites($metier);
            self::assertNotEmpty($capacites, sprintf('Preset vide pour la verticale « %s ».', $metier->value));

            foreach ($capacites as $code) {
                self::assertTrue($catalogue->existe($code), sprintf('Code « %s » du preset « %s » absent du catalogue.', $code, $metier->value));
            }
        }
    }

    public function testPresetPiscineIncluLeControleDaccesEtLePoss(): void
    {
        $capacites = PresetVerticale::capacites(Metier::Piscine);

        self::assertContains('controle_acces', $capacites);
        self::assertContains('poss', $capacites);
    }

    public function testPresetSportIncluLeSepaEtLAccesNocturne(): void
    {
        $capacites = PresetVerticale::capacites(Metier::Sport);

        self::assertContains('sepa', $capacites);
        self::assertContains('acces_nocturne', $capacites);
    }
}
