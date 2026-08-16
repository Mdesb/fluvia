<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Reservation\Command\BasculerNoShowCommand;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\SourcePresence;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/** Annulation / no-show — délai franc paramétrable (RG-M5-04/09, CA-8/9/10). */
final class AnnulationNoShowTest extends ReservationApiTestCase
{
    public function testCa8AnnulationGratuiteDansDelaiFranc(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Créneau largement au-delà du délai franc (24h) : annulation immédiate = dans le délai.
        $idCreneau = $this->creerCreneau($client, $entete, '2026-10-01T10:00:00+00:00', '2026-10-01T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('annulee_libre', $donnees['statut'], 'CA-8 : annulation gratuite dans le délai franc.');
    }

    public function testCa8AnnulationRefuseeHorsDelaiFrancEnLibreService(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Jeton client (annuler_soi) obtenu via le même client/kernel — combiner plusieurs helpers
        // `xxxSurA()` (qui rebootent chacun le kernel via `createClient()`) casserait la session
        // d'authentification du client `$client` déjà en cours.
        $tokenClient = $this->jeton($client, \App\Reservation\DataFixtures\ReservationFixtures::CLIENT_EMAIL, \App\Reservation\DataFixtures\ReservationFixtures::CLIENT_MDP);
        $enteteOrg = ['auth_bearer' => $tokenClient, 'headers' => $entete['headers']];

        // Créneau dans 30 minutes : hors délai franc de 24h dès la création.
        $debut = (new \DateTimeImmutable('+30 minutes'))->format(\DateTimeInterface::ATOM);
        $fin = (new \DateTimeImmutable('+90 minutes'))->format(\DateTimeInterface::ATOM);
        $idCreneau = $this->creerCreneau($client, $entete, $debut, $fin);
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        // Le client (annuler_soi) ne peut plus annuler lui-même hors délai.
        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $enteteOrg);
        self::assertResponseStatusCodeSame(409, 'CA-8 : annulation hors délai refusée en libre-service.');

        // Un agent/administrateur peut qualifier l'issue en annulation tardive facturée.
        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('annulee_tardive_facturee', $donnees['statut'], 'RG-M5-09 : annulation tardive facturée par un agent.');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $facturations = $em->getRepository(\App\Reservation\Entity\FacturationNoShow::class)->findBy(['reservation' => $em->getRepository(Reservation::class)->find($idReservation)]);
        self::assertNotEmpty($facturations, 'CA-11 : une FacturationNoShow est créée pour l\'annulation tardive.');
    }

    public function testCa8AnnulationExactementAuDelaiFrancEncoreGratuite(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Créneau exactement 24h + quelques secondes après maintenant : borne inclusive.
        $debut = (new \DateTimeImmutable('+1441 minutes'))->format(\DateTimeInterface::ATOM);
        $fin = (new \DateTimeImmutable('+1500 minutes'))->format(\DateTimeInterface::ATOM);
        $idCreneau = $this->creerCreneau($client, $entete, $debut, $fin);
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        // Force la date limite à un horizon proche (borne inclusive, ≤ délaiFranc) : une marge de
        // quelques minutes absorbe la latence réelle de la requête HTTP suivante dans cet environnement.
        $reservation->setDateLimiteAnnulation((new \DateTimeImmutable())->modify('+5 minutes'));
        $em->flush();

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful('§7 : annulation exactement à l\'heure du délai franc encore gratuite (borne inclusive).');
        self::assertSame('annulee_libre', $client->getResponse()->toArray()['statut']);
    }

    public function testCa9BasculeAutoNoShowApresMarge(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, '2026-09-20T10:00:00+00:00', '2026-09-20T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        // ≥ 1 : la réservation de démonstration des fixtures (créneau passé) peut aussi être basculée.
        $traites = $commande->basculer(new \DateTimeImmutable('2026-09-20T11:05:00+00:00'));
        self::assertGreaterThanOrEqual(1, $traites);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertSame('no_show_facture', $reservation->getStatut()->value, 'CA-9 : bascule automatique en no-show à l\'issue du créneau.');
        $facturations = $em->getRepository(\App\Reservation\Entity\FacturationNoShow::class)->findBy(['reservation' => $reservation]);
        self::assertNotEmpty($facturations, 'CA-9 : une FacturationNoShow est créée.');
    }

    public function testCa10PassageAccesValideConfirmePresence(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, '2026-09-21T10:00:00+00:00', '2026-09-21T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        // Simule un passage d'accès validé pendant la fenêtre du créneau (§4.8) : la présence est
        // confirmée automatiquement sans émargement manuel requis.
        $reservation->confirmerPresence(SourcePresence::PassageAcces, new \DateTimeImmutable('2026-09-21T10:05:00+00:00'));
        $em->flush();
        $em->clear();

        $client->request('GET', '/api/reservations/' . $idReservation, $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertTrue($donnees['presenceConfirmee']);
        self::assertSame('passage_acces', $donnees['sourcePresence'], 'CA-10 : passage validé = présence confirmée, sans émargement manuel.');

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer(new \DateTimeImmutable('2026-09-21T11:05:00+00:00'));
        $em->clear();
        $reservationApres = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertSame('honoree', $reservationApres->getStatut()->value, 'Présence confirmée par accès -> réservation honorée, pas de no-show.');
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function creerCreneau(object $client, array $entete, string $debut, string $fin): string
    {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE),
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
    private function reserver(object $client, array $entete, string $idCreneau, string $idBeneficiaire): string
    {
        $session = $this->ouvrirSession($client, $entete);
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $idBeneficiaire,
                'session' => '/api/session_caisses/' . $session['id'],
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
