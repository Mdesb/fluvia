<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Unit;

use App\Crm\DataFixtures\CrmFixtures;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Patinoire\DataFixtures\PatinoireFixtures;
use App\Patinoire\Entity\ParcPatins;
use App\Patinoire\Service\ProposeurPointureVoisineHandler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Proposition de pointure voisine en cas de rupture (décision actée, §4.5, plan §0 point 7). */
final class ProposeurPointureVoisineHandlerTest extends KernelTestCase
{
    public function testPropositionOrdreInferieurPuisSuperieur(): void
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

        foreach ([SocleFixtures::class, CrmFixtures::class, PatinoireFixtures::class] as $classe) {
            $container->get($classe)->load($em);
        }

        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etablissement);

        /** @var ProposeurPointureVoisineHandler $handler */
        $handler = $container->get(ProposeurPointureVoisineHandler::class);

        // Fixtures : pointures 41 (dispo 4, 1 en affûtage), 42 (dispo 4, 1 sortie démo), 43 (dispo 5).
        $parc42 = $em->getRepository(ParcPatins::class)->findOneBy(['etablissement' => $etablissement, 'pointure' => 42]);
        self::assertNotNull($parc42);
        // -1 (41) disponible avant +1 (43) : ordre inférieure d'abord.
        self::assertSame(41, $handler->proposer($parc42));

        // Épuise la pointure 41 (-1) et 43 (+1) : bascule sur ±2 (aucune pointure 39/44 en fixture) → null.
        $parc41 = $em->getRepository(ParcPatins::class)->findOneBy(['etablissement' => $etablissement, 'pointure' => 41]);
        $parc43 = $em->getRepository(ParcPatins::class)->findOneBy(['etablissement' => $etablissement, 'pointure' => 43]);
        self::assertNotNull($parc41);
        self::assertNotNull($parc43);
        $parc41->setQuantiteTotale($parc41->getQuantiteEnAffutage());
        $parc43->setQuantiteTotale(0);
        $em->flush();

        self::assertNull($handler->proposer($parc42), 'Aucune pointure voisine disponible dans ±2 (39/44 non déclarées).');

        // Une fois la pointure 43 restockée, elle redevient la proposition (ordre supérieur en repli).
        $parc43->setQuantiteTotale(5);
        $em->flush();
        self::assertSame(43, $handler->proposer($parc42));
    }
}
