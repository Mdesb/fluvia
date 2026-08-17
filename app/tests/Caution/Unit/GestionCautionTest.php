<?php

declare(strict_types=1);

namespace App\Tests\Caution\Unit;

use App\Caution\DataFixtures\CautionFixtures;
use App\Caution\Entity\Caution;
use App\Caution\Entity\GrilleRetenue as GrilleRetenueEntity;
use App\Caution\Enum\ModeRetenue;
use App\Caution\Enum\StatutCaution;
use App\Caution\Service\GestionCaution;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Moteur générique du patron caution (`GestionCaution`) : consignation/restitution, unicité de la
 * caution active par cible, résolution de grille (priorité sous-cible), retenue proposée/validée
 * avec garde-fou RG-SOCLE-07, forçage et relance.
 */
final class GestionCautionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GestionCaution $gestion;
    private Etablissement $etablissement;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $this->em = $em;

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        foreach ([SocleFixtures::class, CautionFixtures::class] as $classe) {
            $container->get($classe)->load($em);
        }

        /** @var GestionCaution $gestion */
        $gestion = $container->get(GestionCaution::class);
        $this->gestion = $gestion;

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etab);
        $this->etablissement = $etab;
    }

    public function testConsignerPuisRestituer(): void
    {
        $cible = Uuid::v4();
        $caution = $this->gestion->consigner($this->etablissement, 'test.cible', $cible, 1500, 'especes');
        $this->em->flush();

        self::assertSame(StatutCaution::Consignee, $caution->getStatut());
        self::assertSame('15.00', $caution->getMontantDecimal());
        self::assertSame((string) $cible, $caution->getReferenceCibleActive());

        $this->gestion->restituer($caution);
        $this->em->flush();

        self::assertSame(StatutCaution::Restituee, $caution->getStatut());
        self::assertNull($caution->getReferenceCibleActive(), 'Restituée : la cible ne reste plus « active » (autorise une nouvelle consignation).');
    }

    public function testDeuxCautionsActivesSurLaMemeCibleSontRefusees(): void
    {
        $cible = Uuid::v4();
        $this->gestion->consigner($this->etablissement, 'test.cible', $cible, 1000);
        $this->em->flush();

        $this->gestion->consigner($this->etablissement, 'test.cible', $cible, 1000);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testResolutionGrillePrioriteSousCibleSurGeneral(): void
    {
        $grilleGenerale = new GrilleRetenueEntity();
        $grilleGenerale->setEtablissement($this->etablissement)->setTypeCible('test.cible')->setMotif('casse')
            ->setMode(ModeRetenue::Forfait)->setMontantCentimes(1500);
        $this->em->persist($grilleGenerale);
        $this->em->flush();

        $resolue = $this->gestion->resoudreGrille($this->etablissement, 'test.cible', 'casse');
        self::assertNotNull($resolue);
        self::assertSame(1500, $resolue->getMontantCentimes());
        self::assertNull($resolue->getSousCible());

        $grilleSpecifique = new GrilleRetenueEntity();
        $grilleSpecifique->setEtablissement($this->etablissement)->setTypeCible('test.cible')->setSousCible('pointure-42')
            ->setMotif('casse')->setMode(ModeRetenue::Forfait)->setMontantCentimes(3000);
        $this->em->persist($grilleSpecifique);
        $this->em->flush();

        $resoluePrioritaire = $this->gestion->resoudreGrille($this->etablissement, 'test.cible', 'casse', 'pointure-42');
        self::assertNotNull($resoluePrioritaire);
        self::assertSame(3000, $resoluePrioritaire->getMontantCentimes(), 'Priorité sous-cible > règle générale.');

        $sansGrille = $this->gestion->resoudreGrille($this->etablissement, 'test.cible', 'motif_inconnu');
        self::assertNull($sansGrille);
    }

    public function testRetenueProposeePuisValideeAuMontantParDefaut(): void
    {
        $cible = Uuid::v4();
        $caution = $this->gestion->consigner($this->etablissement, 'test.cible', $cible, 1500);
        $this->em->flush();

        $grille = new GrilleRetenueEntity();
        $grille->setEtablissement($this->etablissement)->setTypeCible('test.cible')->setMotif('casse')
            ->setMode(ModeRetenue::Forfait)->setMontantCentimes(1500);
        $this->em->persist($grille);
        $this->em->flush();

        $mouvement = $this->gestion->proposerRetenue($caution, 'casse');
        $this->em->flush();
        self::assertSame(1500, $mouvement->getMontantCentimes());
        self::assertFalse($mouvement->estValidee());

        $valide = $this->gestion->validerRetenue($mouvement, null, null, false);
        $this->em->flush();
        self::assertTrue($valide->estValidee());
        self::assertFalse($valide->isForcee());
        self::assertSame(StatutCaution::RetenueTotale, $caution->getStatut(), 'Montant retenu = montant caution → retenue totale.');
    }

    public function testValidationHorsBaremeRefuseeSansAutorisationDeForcage(): void
    {
        $cible = Uuid::v4();
        $caution = $this->gestion->consigner($this->etablissement, 'test.cible', $cible, 1500);
        $this->em->flush();

        $mouvement = $this->gestion->proposerRetenue($caution, 'casse', null, 1500);
        $this->em->flush();

        $this->expectException(AccessDeniedHttpException::class);
        $this->gestion->validerRetenue($mouvement, 2500, null, false);
    }

    public function testValidationHorsBaremeAutoriseeAvecPermissionDeForcage(): void
    {
        $cible = Uuid::v4();
        $caution = $this->gestion->consigner($this->etablissement, 'test.cible', $cible, 1500);
        $this->em->flush();

        $mouvement = $this->gestion->proposerRetenue($caution, 'casse', null, 1000);
        $this->em->flush();

        $valide = $this->gestion->validerRetenue($mouvement, 800, null, true);
        $this->em->flush();
        self::assertTrue($valide->isForcee());
        self::assertSame(StatutCaution::RetenuePartielle, $caution->getStatut(), 'Montant retenu < montant caution → retenue partielle.');
    }

    public function testDoubleValidationRefusee(): void
    {
        $cible = Uuid::v4();
        $caution = $this->gestion->consigner($this->etablissement, 'test.cible', $cible, 1000);
        $this->em->flush();
        $mouvement = $this->gestion->proposerRetenue($caution, 'casse', null, 1000);
        $this->em->flush();
        $this->gestion->validerRetenue($mouvement, null, null, false);
        $this->em->flush();

        $this->expectException(ConflictHttpException::class);
        $this->gestion->validerRetenue($mouvement, null, null, false);
    }

    public function testForcerExigeUnMotifEtRetientLaTotalite(): void
    {
        $cible = Uuid::v4();
        $caution = $this->gestion->consigner($this->etablissement, 'test.cible', $cible, 1000);
        $this->em->flush();

        $agent = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        self::assertInstanceOf(Utilisateur::class, $agent);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->gestion->forcer($caution, $agent, '');
    }

    public function testForcerJournaliseEtRetientLaCautionEnTotalite(): void
    {
        $cible = Uuid::v4();
        $caution = $this->gestion->consigner($this->etablissement, 'test.cible', $cible, 1000);
        $this->em->flush();

        $agent = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        self::assertInstanceOf(Utilisateur::class, $agent);

        $mouvement = $this->gestion->forcer($caution, $agent, 'Objets retirés en régie');
        $this->em->flush();

        self::assertSame(StatutCaution::RetenueTotale, $caution->getStatut());
        self::assertSame(1000, $caution->getMontantRetenuCentimes());
        self::assertSame($agent, $mouvement->getAgent());
        self::assertSame('Objets retirés en régie', $mouvement->getMotif());
    }

    public function testRelancerPuisForcageAutoriseSelonDelai(): void
    {
        $cible = Uuid::v4();
        $caution = $this->gestion->consigner($this->etablissement, 'test.cible', $cible, 1000);
        $this->em->flush();

        $mouvement = $this->gestion->relancer($caution, 3);
        $this->em->flush();

        self::assertFalse($this->gestion->forcageAutorise($caution, new \DateTimeImmutable()), 'Délai non dépassé.');

        $mouvement->setHorodatage(new \DateTimeImmutable('-10 days'));
        $this->em->flush();

        self::assertTrue($this->gestion->forcageAutorise($caution, new \DateTimeImmutable()), 'Délai dépassé.');
    }
}
