<?php

declare(strict_types=1);

namespace App\Tests\Compta\Unit;

use App\Compta\Enum\Qualification;
use App\Compta\Enum\TypeExploitant;
use App\Compta\ValueObject\ParametresRegime;
use PHPUnit\Framework\TestCase;

/**
 * §8 du plan : les 6 points ⚠ EXPERT sont des paramètres à défaut prudent, jamais des branches de
 * code nouvelles. Vérifie les défauts et l'aller-retour tableau ↔ objet.
 */
final class ParametresRegimeTest extends TestCase
{
    public function testDefautsPrudentsParType(): void
    {
        $regie = ParametresRegime::defautPour(TypeExploitant::RegieDirecte);
        self::assertFalse($regie->pcaActif, 'Point EXPERT #3 : PCA désactivé par défaut en régie directe.');

        $dsp = ParametresRegime::defautPour(TypeExploitant::Dsp);
        self::assertTrue($dsp->pcaActif);

        $groupe = ParametresRegime::defautPour(TypeExploitant::GroupePrive);
        self::assertTrue($groupe->pcaActif);
    }

    public function testDefautsGeneraux(): void
    {
        $params = new ParametresRegime();
        self::assertSame(Qualification::Spa, $params->qualificationParDefaut, 'Point EXPERT #1 : défaut SPA/M57.');
        self::assertFalse($params->tauxReduitTvaActif, 'Point EXPERT #2 : taux réduit 2025 inactif par défaut.');
        self::assertTrue($params->nf525PerimetreRegie, 'Point EXPERT #4/5 : certification complète visée par défaut.');
        self::assertFalse($params->genereTitreRegularisationPes, 'Point EXPERT #6 : titre de régularisation désactivé par défaut.');
    }

    public function testAllerRetourTableau(): void
    {
        $params = new ParametresRegime(pcaActif: true, qualificationParDefaut: Qualification::Spic, tauxReduitTvaActif: true, nf525PerimetreRegie: false, genereTitreRegularisationPes: true);
        $reconstruit = ParametresRegime::depuisArray($params->toArray());

        self::assertEquals($params, $reconstruit);
    }
}
