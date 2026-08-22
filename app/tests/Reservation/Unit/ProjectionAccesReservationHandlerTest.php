<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Unit;

use App\Acces\Entity\DroitAcces;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client as CrmClient;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\ProjectionAccesReservation;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutCreneau;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\ProjectionAccesReservationHandler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ProjectionAccesReservationHandler` (plan-acc3.md §7, dernière ligne du tableau de tests) : les deux
 * cas limites du handler lui-même, sans passer par HTTP.
 */
final class ProjectionAccesReservationHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ProjectionAccesReservationHandler $handler;
    private Etablissement $etablissement;
    private Beneficiaire $beneficiaire;

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

        $container->get(SocleFixtures::class)->load($em);
        $container->get(CrmFixtures::class)->load($em);

        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etablissement);
        $this->etablissement = $etablissement;

        $payeur = $em->getRepository(CrmClient::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertNotNull($payeur);
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $payeur]);
        self::assertNotNull($beneficiaire);
        $this->beneficiaire = $beneficiaire;

        /** @var ProjectionAccesReservationHandler $handler */
        $handler = $container->get(ProjectionAccesReservationHandler::class);
        $this->handler = $handler;
    }

    public function testAucuneEcritureSiRessourceNouvrePasAcces(): void
    {
        $reservation = $this->creerReservationConfirmee(ouvreAcces: false);

        $resultat = $this->handler->projeterSiApplicable($reservation);

        self::assertNull($resultat);
        self::assertNull(
            $this->em->getRepository(ProjectionAccesReservation::class)->findOneBy(['reservation' => $reservation]),
            'Aucune ProjectionAccesReservation écrite si Ressource.ouvreAcces=false.',
        );
        self::assertSame(0, (int) $this->em->getRepository(DroitAcces::class)->count([]), 'Aucun DroitAcces créé.');
    }

    public function testAucuneProjectionSiReservationNoccupePlusLaPlace(): void
    {
        // RG-ACC3-01 (garde anti-accès-fantôme, revue de cohérence) : projeter sur une réservation déjà
        // sortie de `occupePlace()` — cas de la course annulation↔projection où l'annulation concurrente
        // a committé AVANT que la projection n'obtienne le verrou — ne crée AUCUN droit, même si la
        // Ressource ouvre un accès. Sans le refresh + garde `occupePlace()`, un DroitAcces `Valide`
        // fantôme serait créé pour une réservation annulée, sans qu'aucun chemin ne le révoque ensuite.
        $reservation = $this->creerReservationConfirmee(ouvreAcces: true);
        $reservation->setStatut(StatutReservation::AnnuleeLibre);
        $this->em->flush();

        $resultat = $this->handler->projeterSiApplicable($reservation);

        self::assertNull($resultat, 'Aucune projection pour une réservation qui n\'occupe plus la place (RG-ACC3-01).');
        self::assertNull(
            $this->em->getRepository(ProjectionAccesReservation::class)->findOneBy(['reservation' => $reservation]),
        );
        self::assertSame(0, (int) $this->em->getRepository(DroitAcces::class)->count([]), 'Aucun DroitAcces fantôme créé.');
    }

    public function testRevocationSansProjectionEstUnNoOpSilencieux(): void
    {
        $reservation = $this->creerReservationConfirmee(ouvreAcces: false);

        // Aucune exception, aucun effet observable : §8 cas limite spec (« annulation d'une
        // réservation jamais projetée »).
        $this->handler->revoquerSiProjete($reservation);

        self::assertNull($this->em->getRepository(ProjectionAccesReservation::class)->findOneBy(['reservation' => $reservation]));
    }

    private function creerReservationConfirmee(bool $ouvreAcces): Reservation
    {
        $ressource = (new Ressource())->setEtablissement($this->etablissement)->setCodeType('terrain')
            ->setLibelle('Terrain Unit ACC-3 ' . uniqid())->setCapacitePropre(4)->setOuvreAcces($ouvreAcces);
        $this->em->persist($ressource);

        $debut = new \DateTimeImmutable('2026-12-01T10:00:00+00:00');
        $creneau = (new Creneau())->setRessource($ressource)->setDebut($debut)->setFin($debut->modify('+60 minutes'))
            ->setCapacite(4)->setEtablissement($this->etablissement)->setStatut(StatutCreneau::Planifie);
        $this->em->persist($creneau);

        $reservation = (new Reservation())->setCreneau($creneau)->setOrganisateur($this->beneficiaire)
            ->setEtablissement($this->etablissement)->setModeDecompte(ModeDecompteReservation::Gratuit)->setMontantDu('0.00')
            ->setStatut(StatutReservation::Confirmee);
        $this->em->persist($reservation);
        $this->em->flush();

        return $reservation;
    }
}
