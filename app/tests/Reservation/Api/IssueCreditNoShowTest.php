<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Acces\Entity\DroitAcces;
use App\Reservation\Command\BasculerNoShowCommand;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Entity\ProjectionAccesReservation;
use App\Reservation\Entity\RegleAnnulation;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\IssueCreditNoShow;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Reservation\Enum\ModeMontantAnnulation;
use App\Reservation\Enum\PorteeRegleAnnulation;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\DeclencherFacturationNoShowHandler;
use App\Tests\Reservation\ReservationApiTestCase;
use App\Tests\Reservation\Support\NoShowCreditEventCollector;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CQ-5 : no-show, l'issue sur le crédit (RG-CQ5-01..10, plan-cq5.md §7, CA-1..10).
 *
 * Aucun crédit réel n'existe aujourd'hui sur un droit `Booking` (§3.2/§9 spec) : chaque test qui a
 * besoin d'un `DroitAcces` créditable le construit en forçant `creditRestant` directement en base après
 * la projection déclenchée par la réservation — même posture que `CardRechargeHandler` testé avant que
 * CQ-6 n'ouvre le crédit côté vente.
 */
final class IssueCreditNoShowTest extends ReservationApiTestCase
{
    public function testCa1RestoredRestitueCreditEtEvenement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->creerRegleActivite($client, IssueCreditNoShow::Restored);
        $idCreneau = $this->creerCreneauTerrainOuvert($client, $entete, '2027-02-01T10:00:00+00:00', '2027-02-01T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau);
        $this->forcerCredit($idReservation, 3);

        /** @var NoShowCreditEventCollector $collecteur */
        $collecteur = static::getContainer()->get(NoShowCreditEventCollector::class);
        $collecteur->reset();

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer(new \DateTimeImmutable('2027-02-01T11:05:00+00:00'));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $droit = $this->droitDeReservation($idReservation);
        self::assertSame(4, $droit->getCreditRestant(), 'CA-1 : creditRestant 3 -> 4.');

        $facturation = $em->getRepository(FacturationNoShow::class)->findOneBy(['reservation' => $em->getRepository(Reservation::class)->find($idReservation)]);
        self::assertNotNull($facturation);
        self::assertTrue($facturation->isCreditRestitue(), 'CA-1 : FacturationNoShow.creditRestitue=true.');
        self::assertSame('restored', $facturation->getIssueCreditNoShow()?->value);

        // BasculerNoShowCommand traite TOUS les créneaux échus, y compris la réservation de démonstration
        // des fixtures (même remarque que AnnulationNoShowTest::testCa9BasculeAutoNoShowApresMarge) :
        // on filtre sur le sujet pour isoler l'événement de CE test.
        $evenements = $this->evenementsPourReservation($collecteur->evenementsNoShow(), $idReservation);
        self::assertCount(1, $evenements);
        self::assertSame('restored', $evenements[0]->payload['creditIssue'] ?? null, 'CA-1 : booking.no_show.payload.creditIssue=restored.');
        self::assertSame(1, $evenements[0]->payload['creditRestoredAmount'] ?? null, 'CA-1 : booking.no_show.payload.creditRestoredAmount=1.');
        self::assertEmpty($this->evenementsPourReservation($collecteur->evenementsRescheduleRequested(), $idReservation), 'CA-1 : Restored (sans report) ne publie pas booking.reschedule_requested.');
    }

    public function testCa2DecrementedLaisseCreditInchange(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->creerRegleActivite($client, IssueCreditNoShow::Decremented);
        $idCreneau = $this->creerCreneauTerrainOuvert($client, $entete, '2027-02-02T10:00:00+00:00', '2027-02-02T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau);
        $this->forcerCredit($idReservation, 3);

        /** @var NoShowCreditEventCollector $collecteur */
        $collecteur = static::getContainer()->get(NoShowCreditEventCollector::class);
        $collecteur->reset();

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer(new \DateTimeImmutable('2027-02-02T11:05:00+00:00'));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $droit = $this->droitDeReservation($idReservation);
        self::assertSame(3, $droit->getCreditRestant(), 'CA-2 : creditRestant inchangé.');

        $facturation = $em->getRepository(FacturationNoShow::class)->findOneBy(['reservation' => $em->getRepository(Reservation::class)->find($idReservation)]);
        self::assertNotNull($facturation);
        self::assertTrue($facturation->isCreditActionne());
        self::assertFalse($facturation->isCreditRestitue());

        $evenements = $this->evenementsPourReservation($collecteur->evenementsNoShow(), $idReservation);
        self::assertCount(1, $evenements);
        self::assertSame('decremented', $evenements[0]->payload['creditIssue'] ?? null);
        self::assertSame(0, $evenements[0]->payload['creditRestoredAmount'] ?? null);
    }

    public function testCa3DefautRestoredWithRescheduleSansPrecision(): void
    {
        [$client, $entete, $idEtab] = $this->adminSurA();
        $client->disableReboot();

        $client->request('POST', '/api/reservation_regle_annulations', $entete + [
            'json' => ['etablissement' => '/api/etablissements/' . $idEtab],
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('restored_with_reschedule', $donnees['issueCreditNoShow'], 'CA-3 : défaut D27 sans précision.');
    }

    public function testCa4RestoredWithRescheduleEvenementPublieAucunCreneauPropose(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->creerRegleActivite($client, IssueCreditNoShow::RestoredWithReschedule);
        $idCreneau = $this->creerCreneauTerrainOuvert($client, $entete, '2027-02-03T10:00:00+00:00', '2027-02-03T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau);
        $this->forcerCredit($idReservation, 3);

        /** @var NoShowCreditEventCollector $collecteur */
        $collecteur = static::getContainer()->get(NoShowCreditEventCollector::class);
        $collecteur->reset();

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer(new \DateTimeImmutable('2027-02-03T11:05:00+00:00'));

        $droit = $this->droitDeReservation($idReservation);
        self::assertSame(4, $droit->getCreditRestant(), 'CA-4 : crédit restitué.');

        $reschedule = $collecteur->evenementsRescheduleRequested();
        self::assertCount(1, $reschedule, 'CA-4 : booking.reschedule_requested publié.');
        self::assertSame((string) $droit->getId(), $reschedule[0]->payload['droitId'] ?? null);
        self::assertSame($idReservation, $reschedule[0]->payload['reservationRef'] ?? null);
        self::assertSame((string) $idCreneau, $reschedule[0]->payload['slotId'] ?? null);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $facturation = $em->getRepository(FacturationNoShow::class)->findOneBy(['reservation' => $em->getRepository(Reservation::class)->find($idReservation)]);
        self::assertNotNull($facturation);

        $client->request('GET', '/api/reservation_facturation_no_shows/' . $facturation->getId(), $entete);
        self::assertResponseIsSuccessful();
        $donneesApi = $client->getResponse()->toArray();
        self::assertTrue($donneesApi['rescheduleRequested'] ?? null, 'CA-4 : rescheduleRequested=true côté API.');
        self::assertTrue($donneesApi['creditRestitue'] ?? null);

        // RG-CQ5-09/D27 : dégradation explicite — aucune entité/route « proposition de créneau » n'existe
        // dans le code (Smart Flow/SF-2 absent). Assertion négative documentée par le grep suivant.
        $sortie = [];
        exec('grep -ril "propositioncreneau\|smartflow" ' . escapeshellarg(dirname(__DIR__, 4) . '/src') . ' 2>/dev/null', $sortie);
        self::assertEmpty($sortie, 'CA-4 : aucun mécanisme de proposition de créneau réel dans app/src (Smart Flow/SF-2 absent).');
    }

    public function testCa5AucunDroitProjeteAucunUpdateAucunChampNeMent(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->creerRegleActivite($client, IssueCreditNoShow::Restored);
        // Ressource « salle » : ouvreAcces=false par défaut -> aucune projection.
        $idCreneau = $this->creerCreneau($client, $entete, ReservationFixtures::RESSOURCE_SALLE_LIBELLE, '2027-02-04T10:00:00+00:00', '2027-02-04T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        self::assertSame(0, (int) $em->getRepository(DroitAcces::class)->count([]), 'CA-5 : aucun DroitAcces créé.');

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer(new \DateTimeImmutable('2027-02-04T11:05:00+00:00'));

        $em->clear();
        $facturation = $em->getRepository(FacturationNoShow::class)->findOneBy(['reservation' => $em->getRepository(Reservation::class)->find($idReservation)]);
        self::assertNotNull($facturation);
        self::assertFalse($facturation->isCreditActionne());
        self::assertFalse($facturation->isCreditRestitue());
        self::assertFalse($facturation->isRescheduleRequested());
        self::assertSame(0, (int) $em->getRepository(DroitAcces::class)->count([]), 'CA-5 : toujours aucun DroitAcces.');
    }

    public function testCa6DroitProjeteSansCreditMemeResultatQueCa5(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->creerRegleActivite($client, IssueCreditNoShow::Restored);
        $idCreneau = $this->creerCreneauTerrainOuvert($client, $entete, '2027-02-05T10:00:00+00:00', '2027-02-05T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau);
        // creditRestant NON forcé : cas universel réel aujourd'hui (§3.2 spec).

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer(new \DateTimeImmutable('2027-02-05T11:05:00+00:00'));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $facturation = $em->getRepository(FacturationNoShow::class)->findOneBy(['reservation' => $em->getRepository(Reservation::class)->find($idReservation)]);
        self::assertNotNull($facturation);
        self::assertFalse($facturation->isCreditActionne(), 'CA-6 : creditRestant=null -> même résultat que CA-5.');
        self::assertFalse($facturation->isCreditRestitue());
    }

    public function testCa7PrecedenceActiviteSurEtablissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Règle Activité (précédente sur la règle Établissement de démonstration, RestoredWithReschedule).
        $this->creerRegleActivite($client, IssueCreditNoShow::Decremented);

        $idCreneau = $this->creerCreneauTerrainOuvert($client, $entete, '2027-02-06T10:00:00+00:00', '2027-02-06T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau);
        $this->forcerCredit($idReservation, 3);

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer(new \DateTimeImmutable('2027-02-06T11:05:00+00:00'));

        $droit = $this->droitDeReservation($idReservation);
        self::assertSame(3, $droit->getCreditRestant(), 'CA-7 : règle Activité (Decremented) prioritaire sur la règle Établissement.');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $facturation = $em->getRepository(FacturationNoShow::class)->findOneBy(['reservation' => $em->getRepository(Reservation::class)->find($idReservation)]);
        self::assertSame('decremented', $facturation->getIssueCreditNoShow()?->value);
    }

    public function testCa8IdempotenceRebasculementEchoueSoldeIncrementeUneFois(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->creerRegleActivite($client, IssueCreditNoShow::Restored);
        $idCreneau = $this->creerCreneauTerrainOuvert($client, $entete, '2027-02-07T10:00:00+00:00', '2027-02-07T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau);
        $this->forcerCredit($idReservation, 3);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var DeclencherFacturationNoShowHandler $handler */
        $handler = static::getContainer()->get(DeclencherFacturationNoShowHandler::class);

        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertNotNull($reservation);

        $facturation1 = $handler->declencher($reservation, StatutReservation::NoShowFacture);
        self::assertNotNull($facturation1);
        self::assertTrue($facturation1->isCreditRestitue());

        $exceptionLevee = false;
        try {
            $handler->declencher($reservation, StatutReservation::NoShowFacture);
        } catch (\Throwable) {
            $exceptionLevee = true;
        }
        self::assertTrue($exceptionLevee, 'CA-8 : le second appel doit échouer (contrainte unique reservation_id).');

        // EntityManager potentiellement invalidé par l'échec de flush() ci-dessus : on repart d'un
        // gestionnaire frais pour vérifier l'état réel en base.
        $emFrais = static::getContainer()->get('doctrine')->resetManager();
        $droitApres = $this->droitDeReservation($idReservation, $emFrais);
        self::assertSame(4, $droitApres->getCreditRestant(), 'CA-8 : le solde n\'a été incrémenté qu\'une seule fois.');
    }

    public function testCa9AnnulationTardiveMemeMouvementQueNoShowAutomatique(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->creerRegleActivite($client, IssueCreditNoShow::Restored);
        $idCreneau = $this->creerCreneauTerrainOuvert($client, $entete, '2027-02-08T10:00:00+00:00', '2027-02-08T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau);
        $this->forcerCredit($idReservation, 3);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        $reservation->setDateLimiteAnnulation((new \DateTimeImmutable())->modify('-5 minutes'));
        $em->flush();

        /** @var NoShowCreditEventCollector $collecteur */
        $collecteur = static::getContainer()->get(NoShowCreditEventCollector::class);
        $collecteur->reset();

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('annulee_tardive_facturee', $client->getResponse()->toArray()['statut']);

        $em->clear();
        $droit = $this->droitDeReservation($idReservation);
        self::assertSame(4, $droit->getCreditRestant(), 'CA-9 : même restitution qu\'un no-show automatique.');

        $cancelled = $collecteur->evenementsCancelled();
        self::assertNotEmpty($cancelled);
        self::assertSame('restored', end($cancelled)->payload['creditIssue'] ?? null, 'CA-9 : booking.cancelled.payload.creditIssue=restored.');
    }

    public function testCa10OrthogonaliteFacturationEtRestitution(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $activite = $em->getRepository(Activite::class)->findOneBy(['libelle' => ReservationFixtures::ACTIVITE_PADEL_LIBELLE]);
        self::assertNotNull($activite);
        $regle = (new RegleAnnulation())->setEtablissement($activite->getEtablissement())
            ->setPortee(PorteeRegleAnnulation::Activite)->setCibleActivite($activite)
            ->setDelaiFrancMinutes(1440)->setModeMontant(ModeMontantAnnulation::Fixe)->setValeurMontant('10.00')
            ->setModeFacturation(ModeFacturationNoShow::DebitPmv)->setIssueCreditNoShow(IssueCreditNoShow::Restored)
            ->setMargePostCreneauMinutes(0)->setActif(true);
        $em->persist($regle);
        $em->flush();

        $idCreneau = $this->creerCreneauTerrainOuvert($client, $entete, '2027-02-09T10:00:00+00:00', '2027-02-09T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau);
        $this->forcerCredit($idReservation, 3);

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer(new \DateTimeImmutable('2027-02-09T11:05:00+00:00'));

        $em->clear();
        $facturation = $em->getRepository(FacturationNoShow::class)->findOneBy(['reservation' => $em->getRepository(Reservation::class)->find($idReservation)]);
        self::assertNotNull($facturation);
        self::assertSame('facturee', $facturation->getStatut()->value, 'CA-10 : débit PMV automatique effectué.');
        self::assertNotNull($facturation->getVenteRattachee());

        $droit = $this->droitDeReservation($idReservation);
        self::assertSame(4, $droit->getCreditRestant(), 'CA-10 : le crédit est restitué indépendamment de la facturation.');
    }

    public function testAucuneRegleActiveAucuneDecisionDeCredit(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ($em->getRepository(RegleAnnulation::class)->findAll() as $regle) {
            $regle->setActif(false);
        }
        $em->flush();

        $idCreneau = $this->creerCreneauTerrainOuvert($client, $entete, '2027-02-10T10:00:00+00:00', '2027-02-10T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau);
        $this->forcerCredit($idReservation, 3);

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer(new \DateTimeImmutable('2027-02-10T11:05:00+00:00'));

        $em->clear();
        $facturations = $em->getRepository(FacturationNoShow::class)->findBy(['reservation' => $em->getRepository(Reservation::class)->find($idReservation)]);
        self::assertEmpty($facturations, 'Aucune RegleAnnulation active -> aucune FacturationNoShow, donc aucune décision de crédit.');

        $droit = $this->droitDeReservation($idReservation);
        self::assertSame(3, $droit->getCreditRestant(), 'Aucune restitution par défaut malgré RestoredWithReschedule = défaut d\'une règle CRÉÉE, pas d\'une absence de règle.');
    }

    // ------------------------------------------------------------------- utilitaires

    private function creerRegleActivite(object $client, IssueCreditNoShow $issue): RegleAnnulation
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $activite = $em->getRepository(Activite::class)->findOneBy(['libelle' => ReservationFixtures::ACTIVITE_PADEL_LIBELLE]);
        self::assertNotNull($activite);

        $regle = (new RegleAnnulation())->setEtablissement($activite->getEtablissement())
            ->setPortee(PorteeRegleAnnulation::Activite)->setCibleActivite($activite)
            ->setDelaiFrancMinutes(1440)->setModeMontant(ModeMontantAnnulation::Fixe)->setValeurMontant('10.00')
            ->setModeFacturation(ModeFacturationNoShow::FactureAEncaisser)->setIssueCreditNoShow($issue)
            ->setMargePostCreneauMinutes(0)->setActif(true);
        $em->persist($regle);
        $em->flush();

        return $regle;
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function creerCreneauTerrainOuvert(object $client, array $entete, string $debut, string $fin): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $terrain = $em->getRepository(Ressource::class)->findOneBy(['libelle' => ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE]);
        self::assertNotNull($terrain);
        if (!$terrain->isOuvreAcces()) {
            $terrain->setOuvreAcces(true);
            $em->flush();
        }

        return $this->creerCreneau($client, $entete, ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE, $debut, $fin);
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function creerCreneau(object $client, array $entete, string $libelleRessource, string $debut, string $fin): string
    {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource($libelleRessource),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_PADEL_LIBELLE),
                'debut' => $debut,
                'fin' => $fin,
                'capacite' => 4,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function reserver(object $client, array $entete, string $idCreneau): string
    {
        $session = $this->ouvrirSession($client, $entete);
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
                'session' => '/api/session_caisses/' . $session['id'],
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }

    /** Force `creditRestant` sur le DroitAcces projeté (fixture factice, décision 6 du mandat, plan §7). */
    private function forcerCredit(string $idReservation, int $credit): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertNotNull($reservation);
        $projection = $em->getRepository(ProjectionAccesReservation::class)->findOneBy(['reservation' => $reservation]);
        self::assertNotNull($projection, 'Projection attendue (Ressource.ouvreAcces=true).');
        $droit = $em->getRepository(DroitAcces::class)->find($projection->getDroitAccesRef());
        self::assertNotNull($droit);
        $droit->setCreditRestant($credit);
        $em->flush();
    }

    /**
     * `BasculerNoShowCommand` traite tous les créneaux échus (y compris la réservation de démonstration
     * des fixtures, cf. `AnnulationNoShowTest::testCa9BasculeAutoNoShowApresMarge`) : les tests qui
     * comptent des événements filtrent sur le sujet (`Reservation`, id) pour isoler ceux de leur propre
     * réservation.
     *
     * @param list<\App\Platform\Event\DomainEvent> $evenements
     *
     * @return list<\App\Platform\Event\DomainEvent>
     */
    private function evenementsPourReservation(array $evenements, string $idReservation): array
    {
        return array_values(array_filter(
            $evenements,
            static fn ($e) => $e->subject->type === 'Reservation' && $e->subject->id === $idReservation,
        ));
    }

    private function droitDeReservation(string $idReservation, ?EntityManagerInterface $em = null): DroitAcces
    {
        /** @var EntityManagerInterface $em */
        $em ??= static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertNotNull($reservation);
        $projection = $em->getRepository(ProjectionAccesReservation::class)->findOneBy(['reservation' => $reservation]);
        self::assertNotNull($projection);
        $droit = $em->getRepository(DroitAcces::class)->find($projection->getDroitAccesRef());
        self::assertNotNull($droit);

        return $droit;
    }
}
