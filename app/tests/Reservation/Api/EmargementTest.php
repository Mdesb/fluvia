<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Reservation\Command\BasculerNoShowCommand;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Reservation;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `POST /reservation/reservations/{id}/emarger` — la route qui n'avait AUCUN test et AUCUN écran.
 *
 * ── ⚠ CE QUE SON ABSENCE COÛTAIT, ET POURQUOI PERSONNE NE LE VOYAIT ───────────────────────────
 *
 * `BasculerNoShowCommand` fait, pour chaque réservation encore confirmée après le créneau :
 *
 *     isPresenceConfirmee() ? Honoree : NoShowFacture
 *
 * Or `presenceConfirmee` n'a **qu'un seul écrivain** dans tout `app/src` — `EmargerProcessor` — et
 * aucun écran ne l'appelait. Mesure : `setPresenceConfirmee` et `confirmerPresence` rendent une
 * occurrence chacun hors de l'entité, toutes deux dans ce processeur, et aucune écriture SQL
 * directe de `presence_confirmee` n'existe. Le drapeau était donc **faux pour toute réservation
 * ayant jamais existé**, et la branche `Honoree` du code mort depuis l'origine.
 *
 * Conséquence si la tâche planifiée tournait : un créneau de vingt personnes toutes venues
 * produirait vingt factures d'absence. Pas les clients distraits — **tous**.
 *
 * ── ⚠ ET CE QUI RENDAIT LE DÉFAUT INVISIBLE EST UN TEST VERT ──────────────────────────────────
 *
 * `AnnulationNoShowTest::testUnePresenceDeSourcePassageAccesMeneAHonoree` — qui s'appelait
 * `testCa10PassageAccesValideConfirmePresence` jusqu'à ce lot — **simule** le passage d'accès en
 * appelant `confirmerPresence()` depuis le test lui-même. Il prouve une chose vraie et utile —
 * qu'une présence confirmée mène à `honoree` plutôt qu'au no-show — mais son nom affirme ce qui
 * n'existe pas : rien, dans le produit, ne confirme la présence depuis un passage. Le critère
 * d'acceptation CA-10 avait donc l'air couvert.
 *
 * C'est la forme la plus coûteuse du test qui ment : il n'est pas faux, il est mal nommé. Personne
 * ne relit un test vert dont le nom dit exactement ce qu'on cherchait à savoir.
 *
 * ── CE QUE CE FICHIER PROUVE ──────────────────────────────────────────────────────────────────
 *
 * L'émargement manuel écrit réellement la présence, la source qu'il déclare est la sienne, il sait
 * revenir en arrière, et la présence enregistrée par ce chemin-là suffit à éviter la facture
 * d'absence. Chaque test porte son témoin négatif : sans le geste, l'état ne bouge pas.
 */
final class EmargementTest extends ReservationApiTestCase
{
    /**
     * Émarger « présent » écrit la présence, avec sa source et son horodatage.
     *
     * ⚠ LE TÉMOIN D'ABORD : à la création, une réservation n'est PAS présente. Sans cette
     * assertion, un modèle qui naîtrait avec `presenceConfirmee = true` — ce qui serait un défaut
     * majeur, tout le monde honoré et personne facturé — rendrait ce test vert.
     */
    public function testEmargerPresentEcritLaPresenceEtSaSource(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, '2026-11-04T10:00:00+00:00', '2026-11-04T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        $client->request('GET', '/api/reservations/'.$idReservation, $entete);
        self::assertResponseIsSuccessful();
        $avant = $client->getResponse()->toArray();
        self::assertFalse(
            $avant['presenceConfirmee'],
            'témoin : une réservation neuve ne doit pas être présente. Si elle l\'est, tout le monde '
            .'serait honoré sans que personne n\'ait émargé, et la suite ne prouve rien.',
        );

        $client->request('POST', '/api/reservation/reservations/'.$idReservation.'/emarger', $entete + [
            'json' => ['statut' => 'present'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/reservations/'.$idReservation, $entete);
        self::assertResponseIsSuccessful();
        $apres = $client->getResponse()->toArray();

        self::assertTrue(
            $apres['presenceConfirmee'],
            'Émarger « présent » n\'écrit pas la présence : le seul écrivain du drapeau ne l\'écrit pas.',
        );
        self::assertSame(
            'emargement_manuel',
            $apres['sourcePresence'],
            'La source doit dire PAR OÙ la présence est arrivée. Devant une contestation de facture '
            .'d\'absence, « pointé à la main » et « tourniquet » ne se défendent pas pareil.',
        );
        self::assertNotNull(
            $apres['dateConfirmationPresence'],
            'Une présence sans horodatage ne se vérifie pas : l\'heure est ce qui la rattache au créneau.',
        );
    }

    /**
     * ⚠ ÉMARGER « ABSENT » REVIENT SUR UNE PRÉSENCE — c'est la correction, et elle compte autant.
     *
     * Le jour où le contrôle d'accès écrira la présence tout seul, un passage attribué à la mauvaise
     * réservation devra pouvoir se corriger à la main. Sans ce chemin, la seule issue serait de
     * modifier la base.
     */
    public function testEmargerAbsentRevientSurUnePresenceEnregistree(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, '2026-11-05T10:00:00+00:00', '2026-11-05T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        $client->request('POST', '/api/reservation/reservations/'.$idReservation.'/emarger', $entete + [
            'json' => ['statut' => 'present'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/reservations/'.$idReservation, $entete);
        $temoin = $client->getResponse()->toArray();
        self::assertTrue($temoin['presenceConfirmee'], 'témoin : il faut une présence avant de pouvoir la retirer.');

        $client->request('POST', '/api/reservation/reservations/'.$idReservation.'/emarger', $entete + [
            'json' => ['statut' => 'absent'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/reservations/'.$idReservation, $entete);
        $apres = $client->getResponse()->toArray();

        self::assertFalse($apres['presenceConfirmee'], 'Émarger « absent » ne retire pas la présence.');
        self::assertNull(
            $apres['sourcePresence'],
            'La source doit partir avec la présence : garder « émargement manuel » sur une absence '
            .'laisserait croire que quelqu\'un a constaté une présence qui n\'existe plus.',
        );
    }

    /**
     * ⚠ LA RAISON D'ÊTRE DE TOUT LE LOT : une personne présente n'est pas facturée pour son absence.
     *
     * C'est le cas qu'on n'a aucune raison d'écrire tant que la branche `Honoree` a l'air
     * atteignable — et c'est exactement pour ça qu'il manquait. Le seul test qui touchait
     * `honoree` posait la présence à la main depuis le test.
     *
     * Ici, la présence arrive par le chemin qu'un exploitant emprunte réellement.
     */
    public function testUnePersonneEmargeePresenteNEstPasFactureePourAbsence(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, '2026-11-06T10:00:00+00:00', '2026-11-06T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        $client->request('POST', '/api/reservation/reservations/'.$idReservation.'/emarger', $entete + [
            'json' => ['statut' => 'present'],
        ]);
        self::assertResponseIsSuccessful();

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer(new \DateTimeImmutable('2026-11-06T11:05:00+00:00'));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertInstanceOf(Reservation::class, $reservation);

        self::assertSame(
            'honoree',
            $reservation->getStatut()->value,
            'Une personne émargée présente est facturée pour son absence. C\'est le défaut que tout '
            .'ce lot existe pour empêcher : sans écran d\'émargement, AUCUNE réservation n\'a jamais '
            .'porté de présence, et la bascule facturait tout le monde — y compris ceux qui étaient là.',
        );

        $facturations = $em->getRepository(\App\Reservation\Entity\FacturationNoShow::class)
            ->findBy(['reservation' => $reservation]);
        self::assertSame([], $facturations, 'Aucune facturation d\'absence ne doit exister pour une présence constatée.');
    }

    /**
     * Le témoin symétrique, sans lequel le test précédent ne prouve rien.
     *
     * ⚠ SANS LUI, UNE BASCULE QUI NE FERAIT PLUS RIEN DU TOUT — commande cassée, créneau non
     * sélectionné, marge mal calculée — rendrait le test voisin vert. « Personne n'est facturé »
     * n'est une bonne nouvelle que si l'on sait que quelqu'un pouvait l'être.
     */
    public function testSansEmargementLaBasculeFactureBienLAbsence(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, '2026-11-07T10:00:00+00:00', '2026-11-07T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer(new \DateTimeImmutable('2026-11-07T11:05:00+00:00'));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertInstanceOf(Reservation::class, $reservation);

        self::assertSame(
            'no_show_facture',
            $reservation->getStatut()->value,
            'témoin : sans émargement, la bascule doit bel et bien facturer l\'absence. Si elle ne '
            .'fait rien, le test voisin ne prouve pas que l\'émargement protège.',
        );
    }

    // ── Outillage, repris de `AnnulationNoShowTest` ─────────────────────────────────────────────

    /**
     * @param array<string, mixed> $entete
     */
    private function creerCreneau(object $client, array $entete, string $debut, string $fin): string
    {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/'.$this->idRessource(ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE),
                'activite' => '/api/reservation_activites/'.$this->idActivite(ReservationFixtures::ACTIVITE_PADEL_LIBELLE),
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
                'creneau' => '/api/reservation_creneaus/'.$idCreneau,
                'organisateur' => '/api/beneficiaires/'.$idBeneficiaire,
                'session' => '/api/session_caisses/'.$session['id'],
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
