<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Reservation\DataFixtures\ReservationFixtures;
use App\Tests\Reservation\ConcurrentSlotWriter;
use App\Tests\Reservation\ReservationApiTestCase;

/**
 * Le compteur d'occupation de la ressource porteuse (`Ressource.occupationCourante`, RG-M5-08) face à
 * une écriture concurrente.
 *
 * Les annulations lisaient le compteur en mémoire, retiraient la quantité, et laissaient le `flush()`
 * écrire la valeur ABSOLUE. Une réservation validée entre-temps voyait son incrément écrasé : le
 * compteur passait sous la réalité, et la jauge globale acceptait une réservation de trop.
 *
 * Le processus concurrent (`ConcurrentSlotWriter::holdOccupationIncrement()`) verrouille la ressource
 * et ajoute des unités, comme une réservation en cours ; l'annulation part pendant qu'il attend. Le
 * compteur final doit porter les deux écritures. Sur le code d'origine, il ne porte que l'annulation.
 */
final class OccupationCounterConcurrencyTest extends ReservationApiTestCase
{
    use ConcurrentSlotWriter;

    private const CONCURRENT_UNITS = 3;

    public function testACancellationKeepsTheUnitsOfAConcurrentBooking(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idRessource = $this->idRessource(ReservationFixtures::RESSOURCE_SALLE_LIBELLE);
        $idCreneau = $this->creerCreneau($client, $entete);
        $idBeneficiaire = $this->idBeneficiairePayeur();

        $annulee = $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 2);
        self::assertResponseIsSuccessful();
        $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 1);
        self::assertResponseIsSuccessful();
        $avant = $this->occupationInDatabase($idRessource);

        $concurrent = $this->holdOccupationIncrement($idRessource, self::CONCURRENT_UNITS);
        $client->request('POST', '/api/reservation/reservations/' . $annulee['id'] . '/annuler', $entete + ['json' => []]);
        $reponse = $client->getResponse()->toArray(false);
        $this->releaseSlotLock($concurrent);

        self::assertResponseIsSuccessful('Annulation : ' . json_encode($reponse));
        self::assertSame(
            $avant + self::CONCURRENT_UNITS - 2,
            $this->occupationInDatabase($idRessource),
            'Le compteur doit garder les unités de la réservation concurrente ET rendre celles de l\'annulation.',
        );
    }

    public function testASlotCancellationKeepsTheUnitsOfAConcurrentBooking(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idRessource = $this->idRessource(ReservationFixtures::RESSOURCE_SALLE_LIBELLE);
        $idCreneau = $this->creerCreneau($client, $entete);
        $idBeneficiaire = $this->idBeneficiairePayeur();

        $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 1);
        self::assertResponseIsSuccessful();
        $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 2);
        self::assertResponseIsSuccessful();
        $avant = $this->occupationInDatabase($idRessource);

        $concurrent = $this->holdOccupationIncrement($idRessource, self::CONCURRENT_UNITS);
        $client->request('POST', '/api/reservation/creneaux/' . $idCreneau . '/annuler', $entete + ['json' => []]);
        $reponse = $client->getResponse()->toArray(false);
        $this->releaseSlotLock($concurrent);

        self::assertResponseIsSuccessful('Annulation du créneau : ' . json_encode($reponse));
        self::assertSame(
            $avant + self::CONCURRENT_UNITS - 3,
            $this->occupationInDatabase($idRessource),
            'Le compteur doit garder les unités de la réservation concurrente ET rendre les trois du créneau annulé.',
        );
    }

    /** @return array<string, mixed> */
    private function reserver(object $client, array $entete, string $idCreneau, string $idBeneficiaire, int $quantite): array
    {
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $idBeneficiaire,
                'quantity' => $quantite,
            ],
        ]);

        return $client->getResponse()->toArray(false);
    }

    /**
     * Un créneau À VENIR : l'annulation reste dans le délai franc, donc libre, et ce test ne dépend
     * pas des règles de facturation d'une annulation tardive.
     *
     * @param array<string, mixed> $entete
     */
    private function creerCreneau(object $client, array $entete): string
    {
        $debut = (new \DateTimeImmutable('+60 days'))->setTime(14, 0);
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_SALLE_LIBELLE),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE),
                'debut' => $debut->format(\DATE_ATOM),
                'fin' => $debut->modify('+1 hour')->format(\DATE_ATOM),
                'capacite' => 10,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
