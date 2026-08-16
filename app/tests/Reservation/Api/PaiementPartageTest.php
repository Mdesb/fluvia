<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Tests\Reservation\ReservationApiTestCase;

/** Paiement partagé (RG-M5-10, CA-13). */
final class PaiementPartageTest extends ReservationApiTestCase
{
    public function testCa13ChaquePartPayeeIndividuellement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idReservation = $this->reserverGratuit($client, $entete);
        $idOrganisateur = $this->idBeneficiairePayeur();
        $idEnfant = $this->idBeneficiaireParPrenom(CrmFixtures::ENFANT_PRENOM);
        $idConjoint = $this->idBeneficiaireParPrenom(CrmFixtures::CONJOINT_PRENOM);

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/participants', $entete + [
            'json' => ['personne' => '/api/beneficiaires/' . $idOrganisateur, 'estOrganisateur' => true, 'partMontant' => '8.00'],
        ]);
        self::assertResponseIsSuccessful();
        $idParticipant1 = $this->dernierParticipantId($client);

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/participants', $entete + [
            'json' => ['personne' => '/api/beneficiaires/' . $idEnfant, 'partMontant' => '8.00'],
        ]);
        self::assertResponseIsSuccessful();
        $idParticipant2 = $this->dernierParticipantId($client);

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/participants', $entete + [
            'json' => ['personne' => '/api/beneficiaires/' . $idConjoint, 'partMontant' => '8.00'],
        ]);
        self::assertResponseIsSuccessful();
        $idParticipant3 = $this->dernierParticipantId($client);

        foreach ([$idParticipant1, $idParticipant2, $idParticipant3] as $idParticipant) {
            $client->request('POST', '/api/reservation/participants/' . $idParticipant . '/payer', $entete + ['json' => []]);
            self::assertResponseIsSuccessful();
            self::assertSame('paye', $client->getResponse()->toArray()['statutPaiement'], 'CA-13 : chaque part est réglée individuellement.');
        }
    }

    public function testCa13DefautPaiementImputeOrganisateur(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idReservation = $this->reserverGratuit($client, $entete);
        $idOrganisateur = $this->idBeneficiairePayeur();
        $idEnfant = $this->idBeneficiaireParPrenom(CrmFixtures::ENFANT_PRENOM);

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/participants', $entete + [
            'json' => ['personne' => '/api/beneficiaires/' . $idOrganisateur, 'estOrganisateur' => true, 'partMontant' => '12.00'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/participants', $entete + [
            'json' => ['personne' => '/api/beneficiaires/' . $idEnfant, 'partMontant' => '12.00'],
        ]);
        self::assertResponseIsSuccessful();

        // Le participant enfant ne règle jamais sa part -> la réservation reste confirmée.
        $client->request('GET', '/api/reservations/' . $idReservation, $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('confirmee', $client->getResponse()->toArray()['statut'], 'CA-13 : la réservation reste confirmée malgré la défection.');

        // À l'issue du créneau, le reste dû est imputé à l'organisateur (§4.10, BasculerNoShowCommand).
        /** @var \App\Reservation\Command\BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(\App\Reservation\Command\BasculerNoShowCommand::class);
        $commande->basculer(new \DateTimeImmutable('2026-12-31T23:59:59+00:00'));

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(\App\Reservation\Entity\Reservation::class)->find($idReservation);
        $enfantParticipant = null;
        foreach ($reservation->getParticipants() as $participant) {
            if (!$participant->isEstOrganisateur()) {
                $enfantParticipant = $participant;
            }
        }
        self::assertNotNull($enfantParticipant);
        self::assertSame('impute_organisateur', $enfantParticipant->getStatutPaiement()->value, 'CA-13 : le reste dû est imputé à l\'organisateur.');
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function reserverGratuit(object $client, array $entete): string
    {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_SALLE_LIBELLE),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE),
                'debut' => '2026-11-30T14:00:00+00:00',
                'fin' => '2026-11-30T15:00:00+00:00',
                'capacite' => 4,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idCreneau = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaus/' . $idCreneau, 'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur()],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }

    /** Extrait l'id du dernier `ParticipantReservation` ajouté (la réponse est la Réservation). */
    private function dernierParticipantId(object $client): string
    {
        $participants = $client->getResponse()->toArray()['participants'] ?? [];
        self::assertNotEmpty($participants);
        $dernier = end($participants);

        return (string) $dernier['id'];
    }
}
