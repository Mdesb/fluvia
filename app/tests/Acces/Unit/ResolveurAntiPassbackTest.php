<?php

declare(strict_types=1);

namespace App\Tests\Acces\Unit;

use App\Acces\Entity\Controleur;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Enum\SensEquipement;
use App\Acces\Service\ResolveurAntiPassback;
use PHPUnit\Framework\TestCase;

/**
 * Résolution anti-passback par spécificité croissante (§4.1 du plan) : défaut système (300 s) →
 * espace → équipement, le plus spécifique gagnant.
 */
final class ResolveurAntiPassbackTest extends TestCase
{
    private function equipement(?bool $espaceActif, ?int $espaceDelai, ?bool $equipActif, ?int $equipDelai): Equipement
    {
        $espace = new EspaceAcces();
        if ($espaceActif !== null) {
            $espace->setAntiPassbackActif($espaceActif);
        }
        if ($espaceDelai !== null) {
            $espace->setAntiPassbackDelai($espaceDelai);
        }
        $controleur = (new Controleur())->setEspace($espace);
        $equipement = (new Equipement())->setControleur($controleur)->setSens(SensEquipement::Entree);
        $equipement->setAntiPassbackActif($equipActif);
        $equipement->setAntiPassbackDelai($equipDelai);

        return $equipement;
    }

    public function testDefautSystemeSiRienConfigure(): void
    {
        $resolveur = new ResolveurAntiPassback();
        $espace = new EspaceAcces(); // valeurs par défaut : actif=true, délai=300
        $controleur = (new Controleur())->setEspace($espace);
        $equipement = (new Equipement())->setControleur($controleur)->setSens(SensEquipement::Entree);

        $resolution = $resolveur->resoudre($equipement);
        self::assertTrue($resolution['actif']);
        self::assertSame(300, $resolution['delai']);
    }

    public function testEspaceSurcharheLeDefaut(): void
    {
        $resolveur = new ResolveurAntiPassback();
        $equipement = $this->equipement(true, 120, null, null);

        $resolution = $resolveur->resoudre($equipement);
        self::assertSame(120, $resolution['delai']);
    }

    public function testEquipementSurchargeLEspace(): void
    {
        $resolveur = new ResolveurAntiPassback();
        $equipement = $this->equipement(true, 120, false, 60);

        $resolution = $resolveur->resoudre($equipement);
        self::assertFalse($resolution['actif']);
        self::assertSame(60, $resolution['delai']);
    }
}
