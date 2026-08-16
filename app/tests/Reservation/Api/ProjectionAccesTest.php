<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\ProjectionAccesReservation;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/** Projection optionnelle d'un droit d'accès sur la fenêtre du créneau (RG-M5-12, CA-15). */
final class ProjectionAccesTest extends ReservationApiTestCase
{
    public function testCa15ProjectionCreeeSurConfirmation(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $terrain = $this->entite(Ressource::class, ['libelle' => ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE]);
        $terrain->setOuvreAcces(true);
        $em->flush();

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $terrain->getId(),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE),
                'debut' => '2026-10-10T10:00:00+00:00',
                'fin' => '2026-10-10T11:00:00+00:00',
                'capacite' => 4,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idCreneau = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaus/' . $idCreneau, 'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur()],
        ]);
        self::assertResponseIsSuccessful();
        $idReservation = $client->getResponse()->toArray()['id'];

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertNotNull($reservation);
        $projection = $em->getRepository(ProjectionAccesReservation::class)->findOneBy(['reservation' => $reservation]);
        self::assertNotNull($projection, 'CA-15 : une projection est créée sur confirmation (Ressource.ouvreAcces=true).');
        self::assertStringStartsWith('2026-10-10T10:00:00', $projection->getFenetreDebut()->format(\DateTimeInterface::ATOM));
        self::assertStringStartsWith('2026-10-10T11:00:00', $projection->getFenetreFin()->format(\DateTimeInterface::ATOM));

        // Vérifie aussi l'exposition API (lecture directe par id, groupe projection_acces:read).
        $client->request('GET', '/api/reservation_projection_acces/' . $projection->getId(), $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertStringStartsWith('2026-10-10T10:00:00', $donnees['fenetreDebut']);
    }

    public function testAucuneProjectionSiRessourceNouvrePasAcces(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_SALLE_LIBELLE),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE),
                'debut' => '2026-10-11T10:00:00+00:00',
                'fin' => '2026-10-11T11:00:00+00:00',
                'capacite' => 4,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idCreneau = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaus/' . $idCreneau, 'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur()],
        ]);
        self::assertResponseIsSuccessful();
        $idReservation = $client->getResponse()->toArray()['id'];

        $client->request('GET', '/api/reservation_projection_acces', $entete + ['query' => ['reservation' => $idReservation]]);
        self::assertResponseIsSuccessful();
        $membres = $client->getResponse()->toArray()['member'] ?? $client->getResponse()->toArray()['hydra:member'];
        self::assertEmpty($membres, 'Aucune projection si la Ressource n\'ouvre pas d\'accès (RG-M5-12 optionnel).');
    }
}
