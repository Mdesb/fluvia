<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Acces\Dto\EvenementPassageDto;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensEquipement;
use App\Acces\Enum\SensPassage;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeEquipement;
use App\Acces\Enum\TypeSupport;
use App\Acces\Entity\DroitAcces;
use App\Acces\Service\ValidationPassageHandler;
use App\Crm\DataFixtures\CrmFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Command\BasculerNoShowCommand;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\ProjectionAccesReservation;
use App\Reservation\Entity\Reservation;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Révocation symétrique (RG-ACC3-05, plan-acc3.md §5.3/§7) : le `DroitAcces` projeté depuis une
 * réservation (ACC-3) est dévalidé quand la réservation quitte `occupePlace()` — annulation libre,
 * annulation tardive facturée, no-show, annulation de créneau.
 */
final class RevocationAccesReservationTest extends ReservationApiTestCase
{
    public function testAnnulationLibreDevalideLeDroit(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->activerAccesTerrain();
        $debut = new \DateTimeImmutable('2027-07-01T10:00:00+00:00');
        $idCreneau = $this->creerCreneau($client, $entete, $debut->format(DATE_ATOM), $debut->modify('+60 minutes')->format(DATE_ATOM));
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        $droit = $this->droitPourReservation($idReservation);
        self::assertSame('valide', $droit->getStatutProjection()->value);

        // Support appairé au droit avant l'annulation (patron TypeDroitAccesPersonnelTest) : permet de
        // vérifier, après révocation, qu'un passage tenté est refusé par le moteur générique inchangé.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etabA = $em->getRepository(Etablissement::class)->find($this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM));
        self::assertNotNull($etabA);
        [$support, $equipement] = $this->appairerSupportEtCreerEquipement($droit, $etabA);

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('annulee_libre', $client->getResponse()->toArray()['statut'], 'CA-3 : annulation dans le délai franc.');

        $em->clear();
        $droitApres = $em->getRepository(DroitAcces::class)->find($droit->getId());
        self::assertNotNull($droitApres);
        self::assertSame('devalide', $droitApres->getStatutProjection()->value, 'CA-3 : le droit projeté est dévalidé à l\'annulation libre.');

        // Un passage tenté ensuite, dans la fenêtre, est refusé (droit dévalidé), sans aucune
        // modification du moteur générique de validation.
        /** @var ValidationPassageHandler $validation */
        $validation = static::getContainer()->get(ValidationPassageHandler::class);
        $passage = $validation->valider(new EvenementPassageDto(
            equipementId: $equipement->getId(),
            identifiantSupport: $support->getIdentifiant(),
            sens: SensPassage::Entree,
            horodatage: $debut->modify('+5 minutes'),
            cleIdempotence: Uuid::v4(),
        ));
        self::assertSame(ResultatPassage::Refuse, $passage->getResultat());
        self::assertSame(CodeMotifRefus::DroitInvalide, $passage->getCodeMotif(), 'CA-3 : passage refusé (droit dévalidé).');
    }

    public function testAnnulationTardiveDevalideLeDroit(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->activerAccesTerrain();
        $idCreneau = $this->creerCreneau($client, $entete, '2027-06-02T10:00:00+00:00', '2027-06-02T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        $droit = $this->droitPourReservation($idReservation);
        self::assertSame('valide', $droit->getStatutProjection()->value);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertNotNull($reservation);
        $reservation->setDateLimiteAnnulation((new \DateTimeImmutable())->modify('-5 minutes'));
        $em->flush();

        // Un agent (reservation.annuler) qualifie l'issue en annulation tardive facturée.
        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('annulee_tardive_facturee', $client->getResponse()->toArray()['statut']);

        $em->clear();
        $droitApres = $em->getRepository(DroitAcces::class)->find($droit->getId());
        self::assertNotNull($droitApres);
        self::assertSame('devalide', $droitApres->getStatutProjection()->value, 'CA-4 (volet tardif) : le droit projeté est dévalidé.');
    }

    public function testNoShowDevalideLeDroit(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->activerAccesTerrain();
        $idCreneau = $this->creerCreneau($client, $entete, '2026-09-20T10:00:00+00:00', '2026-09-20T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        $droit = $this->droitPourReservation($idReservation);
        self::assertSame('valide', $droit->getStatutProjection()->value);

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer(new \DateTimeImmutable('2026-09-20T11:05:00+00:00'));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertNotNull($reservation);
        self::assertSame('no_show_facture', $reservation->getStatut()->value);

        $droitApres = $em->getRepository(DroitAcces::class)->find($droit->getId());
        self::assertNotNull($droitApres);
        self::assertSame('devalide', $droitApres->getStatutProjection()->value, 'CA-4 (volet no-show) : le droit projeté est dévalidé.');
    }

    public function testAnnulationCreneauDevalideLesDroitsDeToutesLesReservations(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->activerAccesTerrain();
        $idCreneau = $this->creerCreneau($client, $entete, '2027-08-02T10:00:00+00:00', '2027-08-02T11:00:00+00:00');
        $idReservation1 = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());
        $idReservation2 = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiaireParPrenom(CrmFixtures::ENFANT_PRENOM));

        $droit1 = $this->droitPourReservation($idReservation1);
        $droit2 = $this->droitPourReservation($idReservation2);
        self::assertSame('valide', $droit1->getStatutProjection()->value);
        self::assertSame('valide', $droit2->getStatutProjection()->value);

        $client->request('POST', '/api/reservation/creneaux/' . $idCreneau . '/annuler', $entete);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $droit1Apres = $em->getRepository(DroitAcces::class)->find($droit1->getId());
        $droit2Apres = $em->getRepository(DroitAcces::class)->find($droit2->getId());
        self::assertNotNull($droit1Apres);
        self::assertNotNull($droit2Apres);
        self::assertSame('devalide', $droit1Apres->getStatutProjection()->value);
        self::assertSame('devalide', $droit2Apres->getStatutProjection()->value, 'Toutes les réservations du créneau annulé voient leur droit dévalidé.');
    }

    public function testReservationGratuiteFonctionneIdentiquement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->activerAccesTerrain();
        $idCreneau = $this->creerCreneau($client, $entete, '2027-07-03T10:00:00+00:00', '2027-07-03T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertNotNull($reservation);
        self::assertSame('gratuit', $reservation->getModeDecompte()->value, '§8 spec : activité gratuite -> ModeDecompteReservation::Gratuit.');

        $droit = $this->droitPourReservation($idReservation);
        self::assertSame(TypeDroitAcces::Booking, $droit->getSourceType());
        self::assertSame('valide', $droit->getStatutProjection()->value, '§8 spec : ouvreAcces seul déclenche la projection, indépendamment du mode de décompte.');
    }

    private function activerAccesTerrain(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $terrain = $this->entite(\App\Reservation\Entity\Ressource::class, ['libelle' => ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE]);
        $terrain->setOuvreAcces(true);
        $em->flush();
    }

    /** @param array<string, mixed> $entete */
    private function creerCreneau(object $client, array $entete, string $debut, string $fin): string
    {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE),
                'debut' => $debut,
                'fin' => $fin,
                'capacite' => 4,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }

    /** @param array<string, mixed> $entete */
    private function reserver(object $client, array $entete, string $idCreneau, string $idBeneficiaire): string
    {
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaus/' . $idCreneau, 'organisateur' => '/api/beneficiaires/' . $idBeneficiaire],
        ]);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent(false));

        return $client->getResponse()->toArray()['id'];
    }

    private function droitPourReservation(string $idReservation): DroitAcces
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertNotNull($reservation);
        $projection = $em->getRepository(ProjectionAccesReservation::class)->findOneBy(['reservation' => $reservation]);
        self::assertNotNull($projection, 'CA-1 : une projection existe (ressource ouvreAcces=true).');
        $droitRef = $projection->getDroitAccesRef();
        self::assertNotNull($droitRef, 'CA-1 : la projection réelle référence un DroitAcces.');
        $droit = $em->getRepository(DroitAcces::class)->find($droitRef);
        self::assertNotNull($droit);

        return $droit;
    }

    /** @return array{0: Support, 1: Equipement} */
    private function appairerSupportEtCreerEquipement(DroitAcces $droit, Etablissement $etablissement): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $espaceSocle = (new Espace())->setNom('Espace ACC-3 test')->setEtablissement($etablissement)->setType('terrain');
        $em->persist($espaceSocle);
        $espaceAcces = (new EspaceAcces())->setLibelle('Zone ACC-3 test')->setEspaceSocle($espaceSocle)->setSeuilFmi(50);
        $em->persist($espaceAcces);
        $controleur = (new Controleur())->setLibelle('Contrôleur ACC-3 test')->setEspace($espaceAcces)->setItboxRef('ITBOX-ACC3-TEST');
        $em->persist($controleur);
        $equipement = (new Equipement())->setLibelle('Tourniquet ACC-3 test')->setControleur($controleur)
            ->setType(TypeEquipement::Tourniquet)->setSens(SensEquipement::Entree);
        $em->persist($equipement);

        $support = (new Support())->setIdentifiant('RFID-ACC3-' . substr((string) Uuid::v4(), 0, 8))
            ->setType(TypeSupport::Rfid)->setEtablissement($etablissement);
        $em->persist($support);

        $appairage = (new Appairage())->setSupport($support)->setDroit($droit)->setMode(ModeAppairage::Caisse)
            ->setActif(true)->setEtablissement($etablissement);
        $em->persist($appairage);

        $em->flush();

        return [$support, $equipement];
    }
}
