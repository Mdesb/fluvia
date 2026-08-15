<?php

declare(strict_types=1);

namespace App\Tests\Sport\Unit;

use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Enum\PeriodeQuota;
use App\Offre\Service\SimulateurQuota;
use App\Recouvrement\DataFixtures\RecouvrementFixtures;
use App\Sport\DataFixtures\SportFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\Acces\DataFixtures\AccesFixtures;
use App\DataFixtures\SocleFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Quota « cours inclus » (CA-14, RG-M1-12) : la formule Gold instanciée par l'abonnement fitness de
 * démonstration porte un service inclus décompté en semaine calendaire, **réutilisé tel quel** par
 * Sport (`SimulateurQuota`, M1, code réel non modifié) — aucun code Sport n'intervient dans le calcul,
 * conformément au plan §6 (« réutilise ServiceInclus/PeriodeQuota M1, pas de code Sport »).
 */
final class QuotaCoursInclusTest extends KernelTestCase
{
    public function testCa14QuotaSeReinitialiseChaqueSemaineCalendaireSansReport(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        // FK_CHECKS désactivé le temps du drop/create (nombreuses tables inter-référencées) : évite les
        // échecs d'ordonnancement DROP/CREATE observés après l'introduction du schéma recouvrement_*.
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        foreach ([SocleFixtures::class, OffreFixtures::class, AccesFixtures::class, CrmFixtures::class, RecouvrementFixtures::class, SportFixtures::class] as $classe) {
            $container->get($classe)->load($em);
        }

        $produitGold = $em->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_GOLD]);
        self::assertNotNull($produitGold);
        $formule = $produitGold->getFormule();
        self::assertNotNull($formule);
        $service = $formule->getServicesInclus()->first();
        self::assertNotFalse($service);
        self::assertSame(PeriodeQuota::SemaineCalendaire, $service->getPeriode());

        $simulateur = new SimulateurQuota();

        // Semaine 1 : 2 consommations dans la fenêtre → quota épuisé (quota=2).
        $lundiSemaine1 = new \DateTimeImmutable('2026-08-10T10:00:00'); // un lundi.
        $consommations = [
            $lundiSemaine1->modify('+1 day'),
            $lundiSemaine1->modify('+2 days'),
        ];
        self::assertSame(0, $simulateur->quotaRestant($service, $lundiSemaine1, $consommations));

        // Semaine 2 (lundi suivant) : réinitialisation SANS report, malgré l'épuisement la semaine
        // précédente (RG-M1-12) — les mêmes consommations, hors de la nouvelle fenêtre, ne comptent pas.
        $lundiSemaine2 = $lundiSemaine1->modify('+7 days');
        self::assertSame(2, $simulateur->quotaRestant($service, $lundiSemaine2, $consommations));
    }
}
