<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Reservation\Command\BackfillResourceOccupancyCommand;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Tests\Reservation\ConcurrentSlotWriter;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `reservation:backfill-resource-occupancy` — le rattrapage des compteurs déjà dérivés.
 *
 * Les compteurs en base portent la dérive des chemins qui décrémentaient sans avoir incrémenté : elle
 * ne se corrige pas toute seule. La commande remet chaque compteur sur la somme des réservations qui
 * tiennent une place.
 */
final class BackfillResourceOccupancyCommandTest extends ReservationApiTestCase
{
    use ConcurrentSlotWriter;

    public function testItPutsADriftedCounterBackOnTheBookings(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idRessource = $this->idRessource(ReservationFixtures::RESSOURCE_SALLE_LIBELLE);
        $idCreneau = $this->creerCreneau($client, $entete);
        $idBeneficiaire = $this->idBeneficiairePayeur();

        $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 2);
        self::assertResponseIsSuccessful();
        $juste = $this->occupationInDatabase($idRessource);

        // La dérive qu'on répare : des unités que plus aucune réservation ne justifie.
        $this->connexion()->executeStatement(
            'UPDATE reservation_ressource SET occupation_courante = occupation_courante + 7 WHERE id = UNHEX(?)',
            [str_replace('-', '', $idRessource)],
        );
        self::assertSame($juste + 7, $this->occupationInDatabase($idRessource));

        // À blanc : la commande annonce l'écart et n'écrit rien.
        $testeur = $this->testeur();
        $testeur->execute(['--dry-run' => true]);
        self::assertStringContainsString('-7', $testeur->getDisplay());
        self::assertSame($juste + 7, $this->occupationInDatabase($idRessource), '--dry-run n\'écrit pas.');

        $testeur = $this->testeur();
        $testeur->execute([]);
        self::assertSame($juste, $this->occupationInDatabase($idRessource), 'Le compteur repart des réservations.');

        // Idempotente : relancée, elle ne trouve plus rien.
        $testeur = $this->testeur();
        $testeur->execute([]);
        self::assertStringContainsString('Aucun écart', $testeur->getDisplay());
        self::assertSame($juste, $this->occupationInDatabase($idRessource));
    }

    private function testeur(): CommandTester
    {
        $commande = static::getContainer()->get(BackfillResourceOccupancyCommand::class);
        \assert($commande instanceof BackfillResourceOccupancyCommand);

        return new CommandTester($commande);
    }

    private function connexion(): Connection
    {
        /** @var Connection $connexion */
        $connexion = static::getContainer()->get('doctrine')->getConnection();

        return $connexion;
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

    /** @param array<string, mixed> $entete */
    private function creerCreneau(object $client, array $entete): string
    {
        $debut = (new \DateTimeImmutable('+45 days'))->setTime(10, 0);
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
