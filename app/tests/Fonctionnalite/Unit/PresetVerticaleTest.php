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

    /**
     * Décision de Maxime du 08/10 : un module qu'aucun préréglage n'active naît masqué du menu. Le
     * préréglage porte donc sa propre verticale (sans quoi une piscine ne verrait pas l'écran
     * « Piscine ») et jamais un module hors mission — qui doit être une capacité pour être masqué.
     */
    public function testChaquePresetActiveSaVerticaleEtAucunModuleHorsMission(): void
    {
        $horsMission = ['affaires', 'projets', 'finance', 'social', 'stay', 'lodging', 'dining', 'connecteurs'];
        $catalogue = new CatalogueCapacites();
        foreach ($horsMission as $code) {
            self::assertTrue($catalogue->existe($code), sprintf('« %s » doit être une capacité pour pouvoir être masqué.', $code));
        }

        foreach (Metier::cases() as $metier) {
            $capacites = PresetVerticale::capacites($metier);
            self::assertContains($metier->value, $capacites, sprintf('Le preset « %s » n’active pas son propre écran.', $metier->value));
            foreach ($horsMission as $code) {
                self::assertNotContains($code, $capacites, sprintf('Le preset « %s » active « %s ».', $metier->value, $code));
            }
        }
    }

    /** Une structure sans métier reconnu (un cinéma : aucun `Metier` ne le décrit) reçoit les communes. */
    public function testSansMetierSeulesLesCapacitesCommunes(): void
    {
        self::assertSame(['comptabilite', 'stock'], PresetVerticale::capacites(null));
    }

    public function testPresetSportIncluLeSepaEtLAccesNocturne(): void
    {
        $capacites = PresetVerticale::capacites(Metier::Sport);

        self::assertContains('sepa', $capacites);
        self::assertContains('acces_nocturne', $capacites);
    }
}
