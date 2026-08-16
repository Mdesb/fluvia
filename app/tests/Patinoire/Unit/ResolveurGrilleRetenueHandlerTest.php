<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Unit;

use App\Crm\DataFixtures\CrmFixtures;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Patinoire\DataFixtures\PatinoireFixtures;
use App\Patinoire\Entity\GrilleRetenue;
use App\Patinoire\Entity\ParcPatins;
use App\Patinoire\Enum\ModeRetenue;
use App\Patinoire\Enum\MotifRetenue;
use App\Patinoire\Service\ResolveurGrilleRetenueHandler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Résolution de la grille de retenue applicable (§4.4) : priorité pointure > établissement. */
final class ResolveurGrilleRetenueHandlerTest extends KernelTestCase
{
    public function testPrioritePointureSurEtablissement(): void
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
        $parc42 = $em->getRepository(ParcPatins::class)->findOneBy(['etablissement' => $etablissement, 'pointure' => 42]);
        self::assertNotNull($parc42);

        /** @var ResolveurGrilleRetenueHandler $resolveur */
        $resolveur = $container->get(ResolveurGrilleRetenueHandler::class);

        // Seule la grille générale établissement (fixture, motif casse, 15.00) existe : c'est elle qui s'applique.
        $grille = $resolveur->resoudre($etablissement, $parc42, MotifRetenue::Casse);
        self::assertNotNull($grille);
        self::assertSame('15.00', $grille->getMontantOuTaux());
        self::assertNull($grille->getParcPatins(), 'Sans règle spécifique, la règle générale établissement s\'applique.');

        // Ajout d'une règle spécifique à la pointure 42 (montant supérieur) : elle doit primer.
        $grilleSpecifique = (new GrilleRetenue())->setEtablissement($etablissement)->setMotif(MotifRetenue::Casse)
            ->setMode(ModeRetenue::Forfait)->setMontantOuTaux('30.00')->setParcPatins($parc42);
        $em->persist($grilleSpecifique);
        $em->flush();

        $grillePrioritaire = $resolveur->resoudre($etablissement, $parc42, MotifRetenue::Casse);
        self::assertNotNull($grillePrioritaire);
        self::assertSame('30.00', $grillePrioritaire->getMontantOuTaux(), 'Priorité pointure > établissement (§4.4).');
        self::assertSame($parc42->getId(), $grillePrioritaire->getParcPatins()?->getId());

        // Aucune règle pour un autre motif : résolution null, montant proposé = repli caution par défaut.
        $sansGrille = $resolveur->resoudre($etablissement, $parc42, MotifRetenue::Perte);
        self::assertNull($sansGrille);
        self::assertSame('15.00', $resolveur->montantPropose($sansGrille, '15.00'));
    }
}
