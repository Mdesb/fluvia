<?php

declare(strict_types=1);

namespace App\Tests\Group\Command;

use App\DataFixtures\SocleFixtures;
use App\Group\Entity\GroupBooking;
use App\Musee\Entity\DossierGroupeScolaire;
use App\Musee\Enum\StatutPaiementDossier;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Creneau;
use App\Tests\Group\GroupApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Absorption musée, Phase B : la migration write-only crée le miroir `App\Group` d'un
 * `DossierGroupeScolaire`, sans toucher le dossier, et se rejoue sans créer de doublon (idempotence
 * par le marqueur de provenance `sourceMuseeDossierId`).
 */
final class MigrateGroupDossiersCommandTest extends GroupApiTestCase
{
    public function testMiroiteUnDossierEtEstIdempotent(): void
    {
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabA);
        $creneau = $em->getRepository(Creneau::class)->findOneBy(['etablissement' => $etabA]);
        self::assertInstanceOf(Creneau::class, $creneau);

        $dossier = (new DossierGroupeScolaire())
            ->setEtablissementScolaire('École de la Tour')
            ->setEffectif(20)
            ->setAccompagnateurs(3)
            ->setCreneauEntree($creneau)
            ->setStatutPaiement(StatutPaiementDossier::BonCommandeEmis)
            ->setEtablissement($etabA);
        $em->persist($dossier);
        $em->flush();
        $dossierId = $dossier->getId();

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('musee:dossiers:migrer-vers-group'));

        // 1re exécution : le dossier est miroité fidèlement.
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $em->clear();
        $booking = $em->getRepository(GroupBooking::class)->findOneBy(['sourceMuseeDossierId' => $dossierId]);
        self::assertInstanceOf(GroupBooking::class, $booking, 'Un miroir GroupBooking doit être créé.');
        self::assertSame(20, $booking->getEffectif());
        self::assertSame(3, $booking->getAccompagnateurs());
        self::assertSame('per_person', $booking->getGrain()->value, 'Le musée est au grain visiteur.');
        self::assertSame('confirmed', $booking->getStatus()->value);
        self::assertSame('purchase_order', $booking->getPaymentStatus()->value);
        self::assertNotNull($booking->getGroup());
        self::assertSame('École de la Tour', $booking->getGroup()->getLabel());

        // Le dossier d'origine n'est pas touché (write-only).
        $dossierApres = $em->getRepository(DossierGroupeScolaire::class)->find($dossierId);
        self::assertInstanceOf(DossierGroupeScolaire::class, $dossierApres, 'Le dossier musée est conservé.');
        self::assertSame(20, $dossierApres->getEffectif());

        // 2e exécution : idempotente, aucun doublon.
        $tester2 = new CommandTester((new Application(self::$kernel))->find('musee:dossiers:migrer-vers-group'));
        $tester2->execute([]);
        $tester2->assertCommandIsSuccessful();

        $em->clear();
        $compte = $em->getRepository(GroupBooking::class)->count(['sourceMuseeDossierId' => $dossierId]);
        self::assertSame(1, $compte, 'La ré-exécution ne crée pas de doublon (idempotent).');
    }
}
