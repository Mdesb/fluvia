<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Ressource;
use App\Reservation\Service\PromotionListeAttenteHandler;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Correctif du 24/08 (défaut préexistant, hors ACT-1) : la promotion de liste d'attente créait une
 * réservation sans prendre sa place sur `Ressource.occupationCourante` (RG-M5-08, CA-14).
 *
 * Ce n'était pas un simple sous-comptage. La réservation issue d'une promotion s'annule ensuite par
 * les chemins ordinaires, qui **décrémentent** — la jauge perdait alors une unité prise par une
 * autre réservation. Ces deux tests verrouillent la symétrie : ce qu'on relâche est ce qu'on a pris.
 */
final class JaugePromotionTest extends ReservationApiTestCase
{
    /** La promotion prend la place que le désistement vient de rendre : la jauge revient à son niveau. */
    public function testLaPromotionPrendSaPlaceSurLaJaugeDeLaRessource(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneauUnePlace($client, $entete);

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idReservation = $client->getResponse()->toArray()['id'];
        self::assertSame(1, $this->occupationSalle(), 'La réservation initiale occupe la jauge.');

        $client->request('POST', '/api/reservation/creneaux/' . $idCreneau . '/liste-attente', $entete + [
            'json' => ['beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiaireParPrenom(CrmFixtures::ENFANT_PRENOM)],
        ]);
        self::assertResponseIsSuccessful();

        // Désistement : la jauge tombe à 0, puis la promotion la remet à 1. Avant le correctif elle
        // restait à 0 — une place occupée que la ressource croyait libre.
        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();

        self::assertSame(1, $this->occupationSalle(), 'Le promu occupe la place qu\'il vient de recevoir.');
    }

    /** Et l'expiration de la promotion la rend : c'est la sortie propre à ce service, la seule qui ne passe par aucun autre chemin. */
    public function testLExpirationDUnePromotionRendLaPlace(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneauUnePlace($client, $entete);

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idReservation = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/reservation/creneaux/' . $idCreneau . '/liste-attente', $entete + [
            'json' => ['beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiaireParPrenom(CrmFixtures::ENFANT_PRENOM)],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->occupationSalle());

        /** @var PromotionListeAttenteHandler $handler */
        $handler = static::getContainer()->get(PromotionListeAttenteHandler::class);
        $handler->expirerPromotionsDepassees(new \DateTimeImmutable('+1 day'));

        self::assertSame(0, $this->occupationSalle(), 'Une promotion expirée relâche exactement ce qu\'elle avait pris.');
    }

    private function occupationSalle(): int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $salle = $em->getRepository(Ressource::class)->findOneBy(['libelle' => ReservationFixtures::RESSOURCE_SALLE_LIBELLE]);
        self::assertNotNull($salle);

        return $salle->getOccupationCourante();
    }

    /** @param array<string, mixed> $entete */
    private function creerCreneauUnePlace(object $client, array $entete): string
    {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_SALLE_LIBELLE),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE),
                'debut' => '2026-09-11T14:00:00+00:00',
                'fin' => '2026-09-11T15:00:00+00:00',
                'capacite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
