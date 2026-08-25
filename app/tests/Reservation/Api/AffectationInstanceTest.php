<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Ressource;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ACT-1 point 2 / D16 — « on réserve un type, l'instance est affectée plus tard ».
 *
 * Le type est une `Ressource` qui porte des sous-ressources ; les instances sont ses enfants. La
 * structure existait déjà — réserver l'enfant, c'est choisir une instance précise ; réserver le
 * parent, c'est réserver un type. Les fixtures fournissent le cas tel quel : le bassin sportif porte
 * deux lignes d'eau.
 */
final class AffectationInstanceTest extends ReservationApiTestCase
{
    /** Réserver le type, puis affecter une instance — et la même instance ne sert pas deux fois. */
    public function testAffectationDUneInstanceEtRefusDuDoublon(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneauSurType($client, $entete, 2);

        $premiere = $this->reserver($client, $entete, $idCreneau);
        self::assertResponseIsSuccessful();
        self::assertNull($premiere['ressourceAffectee'] ?? null, 'Réserver un type n\'affecte aucune instance.');

        $idLigne1 = $this->idRessource(ReservationFixtures::RESSOURCE_LIGNE_1_LIBELLE);
        $this->affecter($client, $entete, $premiere['id'], $idLigne1);
        self::assertResponseIsSuccessful();
        self::assertSame($idLigne1, $this->instanceAffectee($premiere['id']));

        // Deuxième réservation sur le même créneau : la ligne 1 est prise, la ligne 2 est libre.
        $seconde = $this->reserver($client, $entete, $idCreneau);
        self::assertResponseIsSuccessful();

        $this->affecter($client, $entete, $seconde['id'], $idLigne1);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('déjà affectée', $client->getResponse()->toArray(false)['detail'] ?? '');

        $idLigne2 = $this->idRessource(ReservationFixtures::RESSOURCE_LIGNE_2_LIBELLE);
        $this->affecter($client, $entete, $seconde['id'], $idLigne2);
        self::assertResponseIsSuccessful();
        self::assertSame($idLigne2, $this->instanceAffectee($seconde['id']));
    }

    /** Une ressource qui n'est pas une instance du type réservé ne peut pas honorer la réservation. */
    public function testUneRessourceEtrangereAuTypeEstRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneauSurType($client, $entete, 2);

        $reservation = $this->reserver($client, $entete, $idCreneau);
        self::assertResponseIsSuccessful();

        // La salle collective n'est pas une ligne d'eau du bassin : « chambre double » ne peut pas
        // être honorée par un emplacement de camping.
        $this->affecter($client, $entete, $reservation['id'], $this->idRessource(ReservationFixtures::RESSOURCE_SALLE_LIBELLE));
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->instanceAffectee($reservation['id']));
    }

    /** Cloisonnement (D3/D8) : l'instance est désignée par le client, donc confrontée au périmètre serveur. */
    public function testUneInstanceDUnAutreEtablissementEstIntrouvable(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneauSurType($client, $entete, 2);

        $reservation = $this->reserver($client, $entete, $idCreneau);
        self::assertResponseIsSuccessful();

        $this->affecter($client, $entete, $reservation['id'], $this->creerRessourceChezB());
        // 404 et non 403 : confirmer l'existence d'une ressource d'un autre établissement serait déjà une fuite.
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->instanceAffectee($reservation['id']));
    }

    private function creerRessourceChezB(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertNotNull($etabB);

        $ressource = (new Ressource())->setEtablissement($etabB)->setCodeType('ligne_eau')
            ->setLibelle('Ligne B ' . uniqid())->setCapacitePropre(6);
        $em->persist($ressource);
        $em->flush();

        return (string) $ressource->getId();
    }

    private function instanceAffectee(string $idReservation): ?string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $reservation = $em->getRepository(\App\Reservation\Entity\Reservation::class)->find($idReservation);
        self::assertNotNull($reservation);
        $instance = $reservation->getRessourceAffectee();

        return $instance === null ? null : (string) $instance->getId();
    }

    /** @param array<string, mixed> $entete */
    private function affecter(object $client, array $entete, string $idReservation, string $idRessource): void
    {
        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/affecter', $entete + [
            'json' => ['ressource' => '/api/reservation_ressources/' . $idRessource],
        ]);
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function reserver(object $client, array $entete, string $idCreneau): array
    {
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);

        return $client->getResponse()->toArray(false);
    }

    /** Un créneau posé sur le TYPE (le bassin), pas sur une instance. */
    private function creerCreneauSurType(object $client, array $entete, int $capacite): string
    {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_BASSIN_LIBELLE),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE),
                'debut' => '2026-09-12T14:00:00+00:00',
                'fin' => '2026-09-12T15:00:00+00:00',
                'capacite' => $capacite,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
