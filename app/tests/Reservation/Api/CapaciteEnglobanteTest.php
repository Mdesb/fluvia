<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Reservation;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ACT-1 point 3 / D33 — « une table libre ne suffit pas si le service n'a plus de couverts ».
 *
 * Le « service » est un `Creneau` posé sur la ressource **mère** : ici un créneau de quatre places
 * sur le bassin, qui couvre dans le temps un créneau de six places sur une ligne d'eau. Réserver
 * cinq unités sur la ligne est refusé parce que le bassin n'en a que quatre — alors que la ligne,
 * elle, a de la place. C'est exactement le cas que le second niveau existant
 * (`Ressource.occupationCourante`, global et aveugle au temps) ne savait pas exprimer.
 */
final class CapaciteEnglobanteTest extends ReservationApiTestCase
{
    /** Le créneau englobant refuse ce que le créneau visé accepterait. */
    public function testLeServicePlafonneCeQueLaLigneAccepterait(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->creerCreneau($client, $entete, ReservationFixtures::RESSOURCE_BASSIN_LIBELLE, '08:00', '12:00', 4);
        $idLigne = $this->creerCreneau($client, $entete, ReservationFixtures::RESSOURCE_LIGNE_1_LIBELLE, '09:00', '10:00', 6);
        $idBeneficiaire = $this->idBeneficiairePayeur();

        $this->reserver($client, $entete, $idLigne, $idBeneficiaire, 3);
        self::assertResponseIsSuccessful();

        // La ligne a encore trois places sur six. Le bassin n'en a plus qu'une sur quatre : refusé.
        // Avant D33, cette réservation passait — c'est le trou que ce lot ferme.
        $this->reserver($client, $entete, $idLigne, $idBeneficiaire, 2);
        self::assertResponseStatusCodeSame(409);
        $detail = $client->getResponse()->toArray(false)['detail'] ?? '';
        self::assertStringContainsString('englobante', $detail);
        self::assertStringContainsString(ReservationFixtures::RESSOURCE_BASSIN_LIBELLE, $detail, 'Le message doit nommer la ressource qui plafonne.');

        // Ce qui rentre encore dans le bassin passe : la borne est bien 4, pas un refus global.
        $this->reserver($client, $entete, $idLigne, $idBeneficiaire, 1);
        self::assertResponseIsSuccessful();
    }

    /** Les créneaux consommés sont **stockés** (D33), pas redérivés : le visé et l'englobant. */
    public function testLesCreneauxConsommesSontStockes(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idBassin = $this->creerCreneau($client, $entete, ReservationFixtures::RESSOURCE_BASSIN_LIBELLE, '08:00', '12:00', 20);
        $idLigne = $this->creerCreneau($client, $entete, ReservationFixtures::RESSOURCE_LIGNE_1_LIBELLE, '09:00', '10:00', 6);

        $reservation = $this->reserver($client, $entete, $idLigne, $this->idBeneficiairePayeur(), 2);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $entite = $em->getRepository(Reservation::class)->find($reservation['id']);
        self::assertNotNull($entite);

        $consommes = array_map(
            static fn ($creneau): string => (string) $creneau->getId(),
            $entite->getConsumedSlots()->toArray(),
        );
        sort($consommes);
        $attendus = [$idBassin, $idLigne];
        sort($attendus);

        self::assertSame($attendus, $consommes, 'Le visé et l\'englobant sont stockés, et rien d\'autre.');
        self::assertSame($idLigne, (string) $entite->getCreneau()?->getId(), 'Le créneau visé reste unique (RG-M5-01).');
    }

    /** Non-régression : sans créneau englobant, la ligne se remplit jusqu'à sa propre capacité. */
    public function testSansCreneauEnglobantRienNeChange(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idLigne = $this->creerCreneau($client, $entete, ReservationFixtures::RESSOURCE_LIGNE_1_LIBELLE, '09:00', '10:00', 6);
        $idBeneficiaire = $this->idBeneficiairePayeur();

        $this->reserver($client, $entete, $idLigne, $idBeneficiaire, 6);
        self::assertResponseIsSuccessful();

        $this->reserver($client, $entete, $idLigne, $idBeneficiaire, 1);
        self::assertResponseStatusCodeSame(409);
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
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
    private function creerCreneau(object $client, array $entete, string $ressource, string $debut, string $fin, int $capacite): string
    {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource($ressource),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE),
                'debut' => '2026-09-11T' . $debut . ':00+00:00',
                'fin' => '2026-09-11T' . $fin . ':00+00:00',
                'capacite' => $capacite,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
