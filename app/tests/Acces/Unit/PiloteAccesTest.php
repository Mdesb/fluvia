<?php

declare(strict_types=1);

namespace App\Tests\Acces\Unit;

use App\Acces\Adapter\SimulateurAccesAdapter;
use App\Acces\Dto\OuvertureContexte;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\Equipement;
use App\Acces\Enum\EtatControleur;
use App\Acces\Port\PiloteAcces;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ports & adaptateurs matériel (§2 du plan) : le domaine dépend du seul port `PiloteAcces`, résolu
 * par configuration vers le simulateur en dev/test (aucun protocole matériel dans le domaine).
 */
final class PiloteAccesTest extends KernelTestCase
{
    public function testAliasResoutVersLeSimulateurEnTest(): void
    {
        self::bootKernel();
        $pilote = static::getContainer()->get(PiloteAcces::class);

        self::assertInstanceOf(SimulateurAccesAdapter::class, $pilote);
    }

    public function testSimulateurOuvreEtHeartbeatSansAucunProtocoleMateriel(): void
    {
        $simulateur = new SimulateurAccesAdapter();

        $espace = new \App\Acces\Entity\EspaceAcces();
        $controleur = (new Controleur())->setEspace($espace);
        $equipement = (new Equipement())->setLibelle('Test')->setControleur($controleur);

        $resultat = $simulateur->ouvrir($equipement, new OuvertureContexte());
        self::assertTrue($resultat->succes);
        self::assertCount(1, $simulateur->ouvertures());

        $etat = $simulateur->heartbeat($controleur);
        self::assertSame(EtatControleur::EnLigne, $etat->etat);
    }
}
