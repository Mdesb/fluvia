<?php

declare(strict_types=1);

namespace App\Tests\Padel\Api;

use App\DataFixtures\SocleFixtures;
use App\Reservation\Entity\RegleAnnulation;
use App\Reservation\Entity\Reservation;
use App\Tests\Padel\PadelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LA CONFIRMATION D'UNE RÉSERVATION — R15 (a).
 *
 * Maxime : « on met un système où il faut une confirmation de la réservation, par exemple 24 heures
 * avant le début de la session, et lors de la confirmation il faut le paiement ; pour toutes les
 * réservations de moins de 24 heures, le paiement est demandé dès le départ ». Et sur la forme :
 * « ces décisions sont des décisions **métier**, il faut laisser le choix à l'exploitant ».
 *
 * ⚠ LE TEST LE PLUS IMPORTANT DE CE FICHIER EST LE PREMIER, ET IL NE TESTE RIEN DE NEUF.
 * Il prouve que **rien ne change** tant qu'aucune règle ne déclare de délai. Un mécanisme posé sur
 * un module partagé qui basculerait tout le monde d'un coup serait bien pire que l'absence de
 * mécanisme — et c'est exactement ce qu'un test qui ne vérifie que le cas actif laisserait passer.
 */
final class ConfirmationReservationTest extends PadelApiTestCase
{
    /**
     * ⚠ SANS RÈGLE QUI L'EXIGE, RIEN NE CHANGE. Le témoin d'inertie.
     *
     * Aucune `RegleAnnulation` du dépôt ne déclare de `confirmationDelayMinutes`. Toute réservation
     * doit donc rester `confirmee`, sans échéance — le comportement d'avant le 04/09.
     */
    public function testSansRegleLaReservationResteConfirmee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $reservation = $this->reserver($client, $entete, '+7 days');

        self::assertSame(
            'confirmee',
            $reservation['statut'] ?? null,
            'sans règle de confirmation, une réservation naît confirmée — comme avant',
        );
        self::assertNull(
            $reservation['confirmationDueAt'] ?? null,
            'et ne porte AUCUNE échéance : le mécanisme est inerte tant qu’on ne l’active pas',
        );
    }

    /**
     * Avec une règle, la réservation attend sa confirmation, et l'échéance est celle qu'on annonce.
     */
    public function testAvecUneRegleLaReservationAttendSaConfirmation(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->poserRegle(1440); // 24 h, l'exemple donné par Maxime

        $debut = (new \DateTimeImmutable('next monday'))->setTime(19, 0);
        $reservation = $this->reserver($client, $entete, null, $debut);

        self::assertSame('a_confirmer', $reservation['statut'] ?? null, 'elle attend une confirmation');
        self::assertNotNull($reservation['confirmationDueAt'] ?? null, 'et porte une échéance');

        $echeance = new \DateTimeImmutable((string) $reservation['confirmationDueAt']);
        self::assertSame(
            $debut->modify('-1440 minutes')->format('Y-m-d H:i'),
            $echeance->format('Y-m-d H:i'),
            'l’échéance est le début moins le délai — 24 h avant, comme annoncé au joueur',
        );
    }

    /**
     * ⚠ RÉSERVÉ À MOINS DE 24 H : L'ÉCHÉANCE EST MAINTENANT, PAS DANS LE PASSÉ.
     *
     * Maxime : « pour toutes les réservations de moins de 24 heures, le paiement est demandé dès le
     * départ ». Une échéance calculée bêtement tomberait AVANT l'instant de la réservation, et la
     * ferait expirer à la seconde où elle est prise.
     */
    public function testReserveeDansLeDelaiLaConfirmationEstImmediate(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->poserRegle(1440);

        $avant = new \DateTimeImmutable();
        $debut = $avant->modify('+3 hours');
        $reservation = $this->reserver($client, $entete, null, $debut);

        self::assertSame('a_confirmer', $reservation['statut'] ?? null);
        $echeance = new \DateTimeImmutable((string) $reservation['confirmationDueAt']);
        self::assertGreaterThanOrEqual(
            $avant->modify('-1 minute')->getTimestamp(),
            $echeance->getTimestamp(),
            'l’échéance ne doit JAMAIS tomber dans le passé — sinon la réservation expire en naissant',
        );
    }

    /** Confirmer fait basculer le statut et enregistre le moment. */
    public function testConfirmerBasculeLeStatutEtEnregistreLeMoment(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->poserRegle(1440);

        $reservation = $this->reserver($client, $entete, null, (new \DateTimeImmutable('next monday'))->setTime(19, 0));
        $id = basename((string) $reservation['@id']);

        $client->request('POST', '/api/reservation/reservations/' . $id . '/confirmer', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/reservations/' . $id, $entete);
        self::assertResponseIsSuccessful();
        $relu = $client->getResponse()->toArray();

        self::assertSame('confirmee', $relu['statut'] ?? null, 'confirmée pour de bon');
        self::assertNotNull($relu['confirmedAt'] ?? null, 'et le moment est enregistré, pas seulement un booléen');
    }

    /**
     * ⚠ CONFIRMER UNE RÉSERVATION QUI NE DEMANDE RIEN EST UN 422, PAS UN SUCCÈS SILENCIEUX.
     *
     * Sans ce refus, un écran pourrait proposer « confirmer » partout et rendre un succès qui ne
     * veut rien dire — et personne ne saurait plus quelles réservations demandent vraiment une
     * confirmation.
     */
    public function testConfirmerCeQuiNeDemandeRienEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $reservation = $this->reserver($client, $entete, '+7 days');
        $id = basename((string) $reservation['@id']);

        $client->request('POST', '/api/reservation/reservations/' . $id . '/confirmer', $entete + ['json' => []]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Réserve un terrain et rend la réservation socle, relue.
     *
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function reserver(object $client, array $entete, ?string $dans, ?\DateTimeImmutable $debut = null): array
    {
        $debut ??= (new \DateTimeImmutable($dans ?? '+7 days'))->setTime(19, 0);

        $client->request('POST', '/api/padel/terrains/' . $this->idTerrain() . '/reservations', $entete + [
            'json' => [
                'debut' => $debut->format(DATE_ATOM),
                'dureeMinutes' => 90,
                'organisateur' => '/api/beneficiaires/' . $this->idJoueur(1),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idReservation = basename((string) $client->getResponse()->toArray()['reservation']);

        $client->request('GET', '/api/reservations/' . $idReservation, $entete);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray();
    }

    /**
     * Pose une règle d'annulation qui exige une confirmation, sur l'établissement A.
     *
     * ⚠ AUCUNE RÈGLE DU DÉPÔT N'EN DÉCLARE — c'est tout l'intérêt : le mécanisme est inerte, et
     * chaque test qui veut l'exercer doit l'activer lui-même.
     */
    private function poserRegle(int $minutes): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etablissement = $em->getRepository(\App\Organisation\Entity\Etablissement::class)
            ->find($this->idEtablissement(SocleFixtures::ETAB_A_NOM));
        self::assertNotNull($etablissement, 'témoin : l’établissement A existe');

        // ⚠ ON MODIFIE LES RÈGLES EXISTANTES, ON N'EN CRÉE PAS UNE DE PLUS.
        // Mon premier jet en créait une neuve, et les trois tests actifs échouaient :
        // `ResolveurRegleAnnulation` fait un `findOneBy`, et les fixtures posent DÉJÀ des règles
        // sur l'établissement A (`ReservationFixtures:199`, `PadelFixtures:246`). C'est l'une
        // d'elles qui gagnait, sans délai de confirmation.
        //
        // Poser le délai sur toutes les règles de l'établissement est aussi le geste réel d'un
        // exploitant : il configure la règle qu'il a, il n'en empile pas une seconde.
        $regles = $em->getRepository(RegleAnnulation::class)->findBy(['etablissement' => $etablissement]);
        self::assertNotEmpty(
            $regles,
            'témoin : l’établissement A porte au moins une règle d’annulation — sans elle, ce test '
            . 'ne mesurerait rien et passerait en croyant que le mécanisme est inerte',
        );

        foreach ($regles as $regle) {
            $regle->setConfirmationDelayMinutes($minutes);
        }
        $em->flush();
    }
}
