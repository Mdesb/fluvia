<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Unit;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client as CrmClient;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\IssueCreditNoShow;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutCreneau;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\ApplyNoShowCreditIssueHandler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ApplyNoShowCreditIssueHandler` (plan-cq5.md §3.5/§7) : les branches de RG-CQ5-04/05/10 testées
 * directement, sans passer par HTTP. Le `DroitAcces` créditable est construit à la main dans chaque
 * test (§9 spec : aucun crédit réel n'existe aujourd'hui sur un droit `Booking`, même posture que
 * `CardRechargeHandler` testé avant CQ-1 côté vente).
 */
final class ApplyNoShowCreditIssueHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ApplyNoShowCreditIssueHandler $handler;
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

        // La séquence native MariaDB `acces_snapshot_seq` (migration Version20260817192240) n'est PAS
        // recréée par SchemaTool (hors mapping ORM) : le chemin appairage->Support.versionMaj exercé par
        // testAppairageActifBasculeVersionMajSupport passe par VersionSnapshotSequencer, qui l'utilise.
        // Recréée ici de façon idempotente — même patron défensif qu'AccesFixtures::load(), ce test ne
        // chargeant pas AccesFixtures.
        $connection = $em->getConnection();
        $sequenceExiste = (bool) $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'acces_snapshot_seq'"
        );
        if (!$sequenceExiste) {
            $connection->executeStatement('CREATE SEQUENCE acces_snapshot_seq START WITH 1 INCREMENT BY 1');
        }

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

        /** @var ApplyNoShowCreditIssueHandler $handler */
        $handler = $container->get(ApplyNoShowCreditIssueHandler::class);
        $this->handler = $handler;
    }

    public function testDecrementedAucuneEcritureNiRestitution(): void
    {
        $reservation = $this->creerReservationAvecCarteDebitee(creditRestant: 3);

        $resultat = $this->handler->apply($reservation, IssueCreditNoShow::Decremented);

        self::assertTrue($resultat->creditActioned);
        self::assertFalse($resultat->creditRestored);

        $this->em->clear();
        $droit = $this->em->getRepository(DroitAcces::class)->findOneBy(['sourceType' => TypeDroitAcces::CarteQuota]);
        self::assertNotNull($droit);
        self::assertSame(3, $droit->getCreditRestant(), 'RG-CQ5-05 Decremented : creditRestant inchangé en base.');
    }

    public function testRestoredIncrementeAtomiquementEtRafraichit(): void
    {
        $reservation = $this->creerReservationAvecCarteDebitee(creditRestant: 3);

        $resultat = $this->handler->apply($reservation, IssueCreditNoShow::Restored);

        self::assertTrue($resultat->creditActioned);
        self::assertTrue($resultat->creditRestored);

        $droit = $this->em->getRepository(DroitAcces::class)->findOneBy(['sourceType' => TypeDroitAcces::CarteQuota]);
        self::assertNotNull($droit);
        self::assertSame(4, $droit->getCreditRestant(), 'RG-CQ5-05 Restored : +1 en base, preuve du refresh() en mémoire.');
    }

    public function testAppairageActifBasculeVersionMajSupport(): void
    {
        $reservation = $this->creerReservationAvecCarteDebitee(creditRestant: 3);
        $droit = $this->em->getRepository(DroitAcces::class)->find($reservation->getCreditDroitRef());
        self::assertNotNull($droit);

        $support = (new Support())->setIdentifiant('SUP-CQ5-' . uniqid())->setType(TypeSupport::Qr)->setEtablissement($this->etablissement);
        $this->em->persist($support);
        $appairage = (new Appairage())->setSupport($support)->setDroit($droit)->setMode(ModeAppairage::Caisse)
            ->setActif(true)->setEtablissement($this->etablissement);
        $this->em->persist($appairage);
        $this->em->flush();
        $versionInitiale = $support->getVersionMaj();

        $this->handler->apply($reservation, IssueCreditNoShow::RestoredWithReschedule);

        $this->em->refresh($support);
        self::assertGreaterThan($versionInitiale, $support->getVersionMaj(), 'RG-CQ5-05 : Appairage actif -> Support.versionMaj bascule (D23).');
    }

    public function testAucuneCarteDebiteeRetourneSansCredit(): void
    {
        $reservation = $this->creerReservationSansCarte();

        $resultat = $this->handler->apply($reservation, IssueCreditNoShow::Restored);

        self::assertFalse($resultat->creditActioned);
        self::assertFalse($resultat->creditRestored);
        self::assertNull($resultat->droitId);
        self::assertSame(0, (int) $this->em->getRepository(DroitAcces::class)->count([]), 'RG-CQ5-04 : aucun UPDATE, aucun droit créé.');
    }

    public function testCarteSansCreditDecomptableRetourneSansCredit(): void
    {
        // creditRestant = null : une carte sans crédit décomptable ne restitue rien.
        $reservation = $this->creerReservationAvecCarteDebitee(creditRestant: null);

        $resultat = $this->handler->apply($reservation, IssueCreditNoShow::Restored);

        self::assertFalse($resultat->creditActioned);
        self::assertFalse($resultat->creditRestored);
    }

    public function testCloisonnementEtablissementDefensifNoOp(): void
    {
        $reservation = $this->creerReservationAvecCarteDebitee(creditRestant: 3);
        $droit = $this->em->getRepository(DroitAcces::class)->find($reservation->getCreditDroitRef());
        self::assertNotNull($droit);

        // Construit artificiellement un droit d'un autre établissement (RG-CQ5-10, garde défensive C19).
        $autreEtablissement = (new Etablissement())->setNom('Autre établissement CQ-5 ' . uniqid())
            ->setRegion($this->etablissement->getRegion());
        $this->em->persist($autreEtablissement);
        $droit->setEtablissement($autreEtablissement);
        $this->em->flush();

        $resultat = $this->handler->apply($reservation, IssueCreditNoShow::Restored);

        self::assertFalse($resultat->creditActioned, 'RG-CQ5-10 : cloisonnement défensif -> no-op, pas d\'exception.');
        self::assertFalse($resultat->creditRestored);

        $this->em->clear();
        $droitApres = $this->em->getRepository(DroitAcces::class)->find($droit->getId());
        self::assertNotNull($droitApres);
        self::assertSame(3, $droitApres->getCreditRestant(), 'Aucun UPDATE émis quand le cloisonnement échoue.');
    }

    public function testCoursePerdueDroitTrouveMaisUpdateZeroTraceRaceLost(): void
    {
        // Branche de concurrence (plan §8 risque n°5) : un droit créditable est bien trouvé en mémoire,
        // mais la ligne a perdu son crédit en base entre la lecture et l'UPDATE conditionnel. On simule
        // via l'identity map : le droit reste géré à creditRestant=3 (le find() interne du handler le
        // sert depuis le cache, la garde `=== null` passe), mais la ligne DB est mise à NULL, donc
        // l'UPDATE `... AND credit_restant IS NOT NULL` n'affecte aucune ligne.
        $reservation = $this->creerReservationAvecCarteDebitee(creditRestant: 3);
        $droitId = $reservation->getCreditDroitRef();
        self::assertNotNull($droitId);

        $this->em->getConnection()->executeStatement(
            'UPDATE acces_droit_acces SET credit_restant = NULL WHERE id = UNHEX(:hex)',
            ['hex' => bin2hex($droitId->toBinary())],
        );

        $resultat = $this->handler->apply($reservation, IssueCreditNoShow::Restored);

        self::assertTrue($resultat->creditActioned, 'RG-CQ5-06 : un droit a été trouvé -> creditActioned reste true.');
        self::assertFalse($resultat->creditRestored, 'La restitution a échoué (0 ligne affectée) -> creditRestored false.');
        self::assertNotNull($resultat->droitId, 'raceLost() est distinct de noCredit() : le droit trouvé est tracé.');
        self::assertSame((string) $droitId, (string) $resultat->droitId);
    }

    /**
     * **Recentré par CQ-3 + CQ-6.** La version d'origine posait le crédit sur le droit `Booking`
     * PROJETÉ de la réservation, en attendant que CQ-3 y ouvre `creditRestant`. Ce droit ne peut pas
     * porter un solde de carte : il en existe un par réservation et il meurt avec elle. Le solde vit
     * désormais sur un droit de type carte, que la réservation désigne par `creditDroitRef`.
     */
    private function creerReservationAvecCarteDebitee(?int $creditRestant): Reservation
    {
        $reservation = $this->creerReservationSansCarte();

        $droit = (new DroitAcces())->setSourceType(TypeDroitAcces::CarteQuota)
            ->setCreditRestant($creditRestant)
            ->setEtablissement($this->etablissement)
            ->setStatutProjection(StatutProjectionDroit::Valide);
        $this->em->persist($droit);
        $this->em->flush();

        $reservation->setCreditDroitRef($droit->getId());
        $this->em->flush();

        return $reservation;
    }

    private function creerReservationSansCarte(): Reservation
    {
        $ressource = (new Ressource())->setEtablissement($this->etablissement)->setCodeType('terrain')
            ->setLibelle('Terrain Unit CQ-5 ' . uniqid())->setCapacitePropre(4)->setOuvreAcces(true);
        $this->em->persist($ressource);

        $debut = new \DateTimeImmutable('2026-12-05T10:00:00+00:00');
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
