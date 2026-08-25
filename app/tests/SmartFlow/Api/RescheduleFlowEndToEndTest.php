<?php

declare(strict_types=1);

namespace App\Tests\SmartFlow\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutCreneau;
use App\SmartFlow\Command\ExpireSlotWaitlistPromotionsCommand;
use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Enum\RescheduleProposalStatus;
use App\Tests\SmartFlow\SmartFlowApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * I1 bout en bout (RG-SF-08..12, plan-smart-flow.md §5) : simule `booking.reschedule_requested` (publié
 * à la main via l'`EventBus`, patron autorisé par la mission — pas besoin d'un vrai flux Reservation),
 * vérifie qu'une `RescheduleProposal` naît en `searching`, qu'un `Creneau` compatible existant fait
 * passer en `proposed`, et que `POST .../accept` avec une réservation créée via l'API `Reservation`
 * existante clôt en `confirmed`. `POST .../decline` et l'IDOR d'`accept` sont couverts symétriquement.
 */
final class RescheduleFlowEndToEndTest extends SmartFlowApiTestCase
{
    public function testNoShowRestitueDeclencheReportJusquaConfirmation(): void
    {
        [$client, $entete, $idA] = $this->managerOn(SocleFixtures::ETAB_A_NOM);
        [$origin, $compatible] = $this->creerCreneauOrigineEtCompatible($idA);
        $idBeneficiairePayeur = $this->idBeneficiairePayeur();

        $this->publierReschedule($idA, $idBeneficiairePayeur, $origin->getId());

        $reponse = $client->request('GET', '/api/smart-flow/reschedule-proposals', $entete)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        self::assertCount(1, $membres);
        self::assertSame('proposed', $membres[0]['status'], 'RG-SF-09 : un créneau compatible existe, la proposition doit être notifiée.');
        self::assertSame((string) $compatible->getId(), $membres[0]['proposedSlotId']);
        $idProposition = $membres[0]['id'];

        // §0.9 du plan : le client crée sa nouvelle réservation par le chemin normal (API Reservation
        // existante, non modifiée), Smart Flow n'écrit jamais dans Reservation.
        [$adminClient, $adminEntete] = $this->adminOn(SocleFixtures::ETAB_A_NOM);
        $reservation = $adminClient->request('POST', '/api/reservation/reservations', $adminEntete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $compatible->getId(),
                'organisateur' => '/api/beneficiaires/' . $idBeneficiairePayeur,
            ],
        ])->toArray();

        $client->request('POST', '/api/smart-flow/reschedule-proposals/' . $idProposition . '/accept', $entete + [
            'json' => ['confirmedReservationRef' => $reservation['id']],
        ]);
        self::assertResponseIsSuccessful();
        $accepte = $client->getResponse()->toArray();
        self::assertSame('confirmed', $accepte['status'], 'RG-SF-12 : accept clôt la proposition en confirmed.');
        self::assertSame($reservation['id'], $accepte['confirmedReservationRef']);
    }

    public function testAucunCreneauCompatibleResteEnAttente(): void
    {
        [$client, $entete, $idA] = $this->managerOn(SocleFixtures::ETAB_A_NOM);
        $origin = $this->creerCreneauIsole($idA, ReservationFixtures::RESSOURCE_SALLE_LIBELLE);

        $this->publierReschedule($idA, $this->idBeneficiairePayeur(), $origin->getId());

        $reponse = $client->request('GET', '/api/smart-flow/reschedule-proposals', $entete)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        self::assertCount(1, $membres);
        self::assertSame('searching', $membres[0]['status'], 'RG-SF-11 : aucun créneau compatible -> reste en attente, pas d\'échec silencieux.');
        self::assertNull($membres[0]['proposedSlotId']);
    }

    public function testDeclineFermeImmediatement(): void
    {
        [$client, $entete, $idA] = $this->managerOn(SocleFixtures::ETAB_A_NOM);
        $origin = $this->creerCreneauIsole($idA, ReservationFixtures::RESSOURCE_SALLE_LIBELLE);

        $this->publierReschedule($idA, $this->idBeneficiairePayeur(), $origin->getId());

        $reponse = $client->request('GET', '/api/smart-flow/reschedule-proposals', $entete)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        self::assertCount(1, $membres);
        $idProposition = $membres[0]['id'];

        $client->request('POST', '/api/smart-flow/reschedule-proposals/' . $idProposition . '/decline', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('expired', $client->getResponse()->toArray()['status'], 'RG-SF-12 : decline -> expired immédiat.');
    }

    public function testDoubleAcceptRefuseEnConflit409(): void
    {
        [$client, $entete, $idA] = $this->managerOn(SocleFixtures::ETAB_A_NOM);
        [$origin, $compatible] = $this->creerCreneauOrigineEtCompatible($idA);
        $idBeneficiairePayeur = $this->idBeneficiairePayeur();

        $this->publierReschedule($idA, $idBeneficiairePayeur, $origin->getId());

        $reponse = $client->request('GET', '/api/smart-flow/reschedule-proposals', $entete)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        $idProposition = $membres[0]['id'];

        [$adminClient, $adminEntete] = $this->adminOn(SocleFixtures::ETAB_A_NOM);
        $reservation = $adminClient->request('POST', '/api/reservation/reservations', $adminEntete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $compatible->getId(),
                'organisateur' => '/api/beneficiaires/' . $idBeneficiairePayeur,
            ],
        ])->toArray();

        $client->request('POST', '/api/smart-flow/reschedule-proposals/' . $idProposition . '/accept', $entete + [
            'json' => ['confirmedReservationRef' => $reservation['id']],
        ]);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent(false));

        $second = $client->request('POST', '/api/smart-flow/reschedule-proposals/' . $idProposition . '/accept', $entete + [
            'json' => ['confirmedReservationRef' => $reservation['id']],
        ]);
        self::assertSame(409, $second->getStatusCode(), 'Une proposition déjà confirmed ne peut pas être acceptée une seconde fois.');
    }

    public function testDeclineSurPropositionDejaConfirmeeRefuse409(): void
    {
        [$client, $entete, $idA] = $this->managerOn(SocleFixtures::ETAB_A_NOM);
        [$origin, $compatible] = $this->creerCreneauOrigineEtCompatible($idA);
        $idBeneficiairePayeur = $this->idBeneficiairePayeur();

        $this->publierReschedule($idA, $idBeneficiairePayeur, $origin->getId());

        $reponse = $client->request('GET', '/api/smart-flow/reschedule-proposals', $entete)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        $idProposition = $membres[0]['id'];

        [$adminClient, $adminEntete] = $this->adminOn(SocleFixtures::ETAB_A_NOM);
        $reservation = $adminClient->request('POST', '/api/reservation/reservations', $adminEntete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $compatible->getId(),
                'organisateur' => '/api/beneficiaires/' . $idBeneficiairePayeur,
            ],
        ])->toArray();

        $client->request('POST', '/api/smart-flow/reschedule-proposals/' . $idProposition . '/accept', $entete + [
            'json' => ['confirmedReservationRef' => $reservation['id']],
        ]);
        self::assertResponseIsSuccessful();

        $decline = $client->request('POST', '/api/smart-flow/reschedule-proposals/' . $idProposition . '/decline', $entete);
        self::assertSame(409, $decline->getStatusCode(), 'Une proposition confirmed ne doit jamais basculer expired via decline.');
    }

    public function testAcceptSurPropositionSearchingRefuse409(): void
    {
        [$client, $entete, $idA] = $this->managerOn(SocleFixtures::ETAB_A_NOM);
        $origin = $this->creerCreneauIsole($idA, ReservationFixtures::RESSOURCE_SALLE_LIBELLE);

        $this->publierReschedule($idA, $this->idBeneficiairePayeur(), $origin->getId());

        $reponse = $client->request('GET', '/api/smart-flow/reschedule-proposals', $entete)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        self::assertSame('searching', $membres[0]['status']);
        $idProposition = $membres[0]['id'];

        $accept = $client->request('POST', '/api/smart-flow/reschedule-proposals/' . $idProposition . '/accept', $entete + [
            'json' => ['confirmedReservationRef' => (string) Uuid::v4()],
        ]);
        self::assertSame(409, $accept->getStatusCode(), 'Une proposition searching (aucun créneau trouvé) ne peut pas être acceptée.');
    }

    /**
     * CA-5/RG-SF-11 (défaut corrigé, revue de cohérence) : une proposition I1 (report de no-show,
     * `sourceWaitlistEntryRef` NULL) `searching` dont `expiresAt` est dépassé est expirée par la
     * commande `smart-flow:waitlist:expirer`, au même titre qu'une promotion I2.
     */
    public function testExpirationDunePropositionI1SearchingEchue(): void
    {
        [$client, $entete, $idA] = $this->managerOn(SocleFixtures::ETAB_A_NOM);
        $origin = $this->creerCreneauIsole($idA, ReservationFixtures::RESSOURCE_SALLE_LIBELLE);

        $this->publierReschedule($idA, $this->idBeneficiairePayeur(), $origin->getId());

        $reponse = $client->request('GET', '/api/smart-flow/reschedule-proposals', $entete)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        self::assertSame('searching', $membres[0]['status']);
        $idProposition = $membres[0]['id'];

        $em = $this->em();
        $proposition = $em->getRepository(RescheduleProposal::class)->find(Uuid::fromString($idProposition));
        self::assertInstanceOf(RescheduleProposal::class, $proposition);
        self::assertNull($proposition->getSourceWaitlistEntryRef(), 'Proposition I1 : pas de liste d\'attente associée.');
        $proposition->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $em->flush();

        /** @var ExpireSlotWaitlistPromotionsCommand $commande */
        $commande = static::getContainer()->get(ExpireSlotWaitlistPromotionsCommand::class);
        $traites = $commande->expirer(new \DateTimeImmutable());
        self::assertSame(1, $traites);

        $em->refresh($proposition);
        self::assertSame(RescheduleProposalStatus::Expired, $proposition->getStatus());
    }

    public function testAcceptAvecReservationDunAutreClientRefuse422(): void
    {
        [$client, $entete, $idA] = $this->managerOn(SocleFixtures::ETAB_A_NOM);
        [$origin, $compatible] = $this->creerCreneauOrigineEtCompatible($idA);

        $this->publierReschedule($idA, $this->idBeneficiairePayeur(), $origin->getId());

        $reponse = $client->request('GET', '/api/smart-flow/reschedule-proposals', $entete)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        self::assertSame('proposed', $membres[0]['status']);
        $idProposition = $membres[0]['id'];

        // Un autre bénéficiaire (l'enfant, compte distinct) réserve le créneau compatible : la
        // proposition référence le payeur, pas l'enfant (IDOR, §0.9 du plan).
        $idAutreBeneficiaire = $this->idBeneficiaireParPrenom(CrmFixtures::ENFANT_PRENOM);
        [$adminClient, $adminEntete] = $this->adminOn(SocleFixtures::ETAB_A_NOM);
        $reservation = $adminClient->request('POST', '/api/reservation/reservations', $adminEntete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $compatible->getId(),
                'organisateur' => '/api/beneficiaires/' . $idAutreBeneficiaire,
            ],
        ])->toArray();

        // NB : deux clients distincts dans ce test (`$client` = manager, `$adminClient` = admin ci-dessus).
        // `assertResponseStatusCodeSame` lit la réponse du client global (le dernier `createClient`, soit
        // l'admin → 201 de la réservation), pas celle de `$client`. On assert donc sur la réponse de
        // l'accept elle-même.
        $accept = $client->request('POST', '/api/smart-flow/reschedule-proposals/' . $idProposition . '/accept', $entete + [
            'json' => ['confirmedReservationRef' => $reservation['id']],
        ]);
        self::assertSame(422, $accept->getStatusCode(), (string) $accept->getContent(false));
    }

    /**
     * Origine + compatible sur une ressource **isolée** (`Salle collective`, RG-SF-09 « même ressource »)
     * — délibérément pas `Terrain padel n°1` : ce dernier porte déjà un créneau/une réservation de
     * démonstration (`ReservationFixtures`), qui deviendrait lui-même un candidat « compatible » (même
     * ressource) et rendrait la sélection du finder non déterministe pour ce test.
     *
     * @return array{0: Creneau, 1: Creneau} origine, compatible (même ressource, dans la fenêtre)
     */
    private function creerCreneauOrigineEtCompatible(string $idEtablissement): array
    {
        $em = $this->em();
        $etablissement = $em->getRepository(Etablissement::class)->find(Uuid::fromString($idEtablissement));
        self::assertInstanceOf(Etablissement::class, $etablissement);
        $salle = $em->getRepository(Ressource::class)->findOneBy(['libelle' => ReservationFixtures::RESSOURCE_SALLE_LIBELLE]);
        self::assertInstanceOf(Ressource::class, $salle);

        $debutOrigine = new \DateTimeImmutable('+1 day');
        $origin = (new Creneau())->setRessource($salle)
            ->setDebut($debutOrigine)->setFin($debutOrigine->modify('+90 minutes'))
            ->setCapacite(1)->setEtablissement($etablissement)->setStatut(StatutCreneau::Planifie);
        $em->persist($origin);

        $debutCompatible = new \DateTimeImmutable('+3 days');
        $compatible = (new Creneau())->setRessource($salle)
            ->setDebut($debutCompatible)->setFin($debutCompatible->modify('+90 minutes'))
            ->setCapacite(1)->setEtablissement($etablissement)->setStatut(StatutCreneau::Planifie);
        $em->persist($compatible);

        $em->flush();

        return [$origin, $compatible];
    }

    /** Créneau sur une ressource sans aucun autre créneau dans la fenêtre (RG-SF-11 : reste `searching`). */
    private function creerCreneauIsole(string $idEtablissement, string $libelleRessource): Creneau
    {
        $em = $this->em();
        $etablissement = $em->getRepository(Etablissement::class)->find(Uuid::fromString($idEtablissement));
        self::assertInstanceOf(Etablissement::class, $etablissement);
        $ressource = $em->getRepository(Ressource::class)->findOneBy(['libelle' => $libelleRessource]);
        self::assertInstanceOf(Ressource::class, $ressource);

        $debut = new \DateTimeImmutable('+1 day');
        $origin = (new Creneau())->setRessource($ressource)
            ->setDebut($debut)->setFin($debut->modify('+60 minutes'))
            ->setCapacite(1)->setEtablissement($etablissement)->setStatut(StatutCreneau::Planifie);
        $em->persist($origin);
        $em->flush();

        return $origin;
    }

    private function publierReschedule(string $idEtablissement, string $customerId, Uuid $slotId): void
    {
        /** @var EventBus $bus */
        $bus = static::getContainer()->get(EventBus::class);
        $ref = (string) Uuid::v4();

        $bus->publish(new DomainEvent(
            'booking.reschedule_requested',
            new EventTenant(Uuid::fromString($idEtablissement)),
            new EventSubject('Reservation', $ref),
            [
                'customerId' => $customerId,
                'reservationRef' => $ref,
                'slotId' => (string) $slotId,
                'droitId' => (string) Uuid::v4(),
            ],
        ));
    }
}
