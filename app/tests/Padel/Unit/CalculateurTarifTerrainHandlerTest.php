<?php

declare(strict_types=1);

namespace App\Tests\Padel\Unit;

use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Padel\DataFixtures\PadelFixtures;
use App\Padel\Entity\TerrainPadel;
use App\Padel\Enum\StatutJoueurTarif;
use App\Padel\Service\CalculateurTarifTerrainHandler;
use App\Padel\Service\ResolveurStatutJoueurTarif;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Sepa\DataFixtures\SepaFixtures;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Résolution du tarif terrain (plage × statut, RG-PADEL-02), CA-1, matrice pleine/creuse × membre/non-membre. */
final class CalculateurTarifTerrainHandlerTest extends KernelTestCase
{
    public function testMatricePleineCreuseXMembreNonMembre(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        foreach ([
            SocleFixtures::class, OffreFixtures::class, ComptaFixtures::class, VenteFixtures::class,
            CrmFixtures::class, SepaFixtures::class, ReservationFixtures::class, PadelFixtures::class,
        ] as $classe) {
            $container->get($classe)->load($em);
        }

        $terrain = $em->getRepository(TerrainPadel::class)->findOneBy([]);
        self::assertNotNull($terrain);

        $client = $em->getRepository(Client::class)->findOneBy(['email' => PadelFixtures::JOUEUR_EMAIL_PREFIX . '1' . PadelFixtures::JOUEUR_DOMAINE]);
        $joueur = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $client]);
        self::assertNotNull($joueur);

        /** @var CalculateurTarifTerrainHandler $calculateur */
        $calculateur = $container->get(CalculateurTarifTerrainHandler::class);
        /** @var ResolveurStatutJoueurTarif $resolveurStatut */
        $resolveurStatut = $container->get(ResolveurStatutJoueurTarif::class);

        // Lundi 19h (pleine), non-membre par défaut (Risque n°3, stub) : 90 min = 38.00€.
        $lundiPleine = (new \DateTimeImmutable('next monday'))->setTime(19, 0);
        $resultat = $calculateur->resoudre($terrain, $joueur, $lundiPleine, 90);
        self::assertSame('38.00', $resultat->prix);
        self::assertSame(StatutJoueurTarif::NonMembre, $resultat->statutJoueur);
        self::assertSame('pleine', $resultat->libellePlage);

        // Lundi 10h (creuse), non-membre : 60 min = 20.00€.
        $lundiCreuse = (new \DateTimeImmutable('next monday'))->setTime(10, 0);
        $resultat = $calculateur->resoudre($terrain, $joueur, $lundiCreuse, 60);
        self::assertSame('20.00', $resultat->prix);
        self::assertSame('creuse', $resultat->libellePlage);

        // Statut membre forcé (test) : lundi 19h pleine 90min = 28.00€.
        $resolveurStatut->definir($joueur, StatutJoueurTarif::Membre);
        $resultat = $calculateur->resoudre($terrain, $joueur, $lundiPleine, 90);
        self::assertSame('28.00', $resultat->prix);
        self::assertSame(StatutJoueurTarif::Membre, $resultat->statutJoueur);
    }
}
