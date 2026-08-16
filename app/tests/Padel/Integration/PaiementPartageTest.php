<?php

declare(strict_types=1);

namespace App\Tests\Padel\Integration;

use App\Reservation\Command\BasculerNoShowCommand;
use App\Reservation\Entity\Reservation;
use App\Tests\Padel\PadelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Paiement partagé — usage padel (RG-PADEL-04, décision actée « Paiement partagé défaillant », CA-14).
 * Mécanisme **entièrement générique** au socle Réservation (`ParticipantReservation`,
 * `BasculerNoShowCommand`) ; le padel ne fait que consommer le paiement partagé pour répartir le prix
 * du créneau entre 1 à 4 joueurs (aucun code Padel supplémentaire au-delà de `ReserverTerrainProcessor`).
 */
final class PaiementPartageTest extends PadelApiTestCase
{
    public function testCa14ReservationResteConfirmeeEtRestantImputeOrganisateur(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idTerrain = $this->idTerrain();
        $debut = (new \DateTimeImmutable('next monday'))->setTime(19, 0);

        $client->request('POST', '/api/padel/terrains/' . $idTerrain . '/reservations', $entete + [
            'json' => [
                'debut' => $debut->format(DATE_ATOM),
                'dureeMinutes' => 90,
                'organisateur' => '/api/beneficiaires/' . $this->idJoueur(1),
                'joueurs' => [
                    '/api/beneficiaires/' . $this->idJoueur(5),
                    '/api/beneficiaires/' . $this->idJoueur(6),
                    '/api/beneficiaires/' . $this->idJoueur(7),
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idReservation = basename((string) $client->getResponse()->toArray()['reservation']);

        $client->request('GET', '/api/reservations/' . $idReservation, $entete);
        $participants = $client->getResponse()->toArray()['participants'];
        self::assertCount(4, $participants, 'CA-14 : réservation à 4 joueurs.');

        // 3 des 4 joueurs règlent leur part ; le dernier ne règle jamais.
        $nonPayeur = null;
        foreach ($participants as $participant) {
            if ($participant['estOrganisateur'] === true) {
                continue;
            }
            static $regles = 0;
            if ($regles < 2) {
                $client->request('POST', '/api/reservation/participants/' . $participant['id'] . '/payer', $entete + ['json' => []]);
                self::assertResponseIsSuccessful();
                ++$regles;
            } else {
                $nonPayeur = $participant;
            }
        }
        self::assertNotNull($nonPayeur);

        // Le joueur organisateur règle sa propre part.
        foreach ($participants as $participant) {
            if ($participant['estOrganisateur'] === true) {
                $client->request('POST', '/api/reservation/participants/' . $participant['id'] . '/payer', $entete + ['json' => []]);
                self::assertResponseIsSuccessful();
            }
        }

        $client->request('GET', '/api/reservations/' . $idReservation, $entete);
        self::assertSame('confirmee', $client->getResponse()->toArray()['statut'], 'CA-14 : la réservation reste confirmée malgré la défection.');

        // À l'issue du créneau, le reste dû est imputé à l'organisateur (§4.10, BasculerNoShowCommand,
        // générique socle réutilisé tel quel).
        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer($debut->modify('+2 hours'));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        $participantNonPayeur = null;
        foreach ($reservation->getParticipants() as $p) {
            if ((string) $p->getId() === $nonPayeur['id']) {
                $participantNonPayeur = $p;
            }
        }
        self::assertNotNull($participantNonPayeur);
        self::assertSame('impute_organisateur', $participantNonPayeur->getStatutPaiement()->value, 'CA-14 : le reste dû est imputé à l\'organisateur (RG-M5-10).');
    }
}
