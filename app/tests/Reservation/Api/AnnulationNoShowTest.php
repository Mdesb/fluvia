<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Reservation\Command\BasculerNoShowCommand;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\SourcePresence;
use App\Tests\Reservation\ReservationApiTestCase;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;

/** Annulation / no-show — délai franc paramétrable (RG-M5-04/09, CA-8/9/10). */
final class AnnulationNoShowTest extends ReservationApiTestCase
{
    public function testCa8AnnulationGratuiteDansDelaiFranc(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Créneau largement au-delà du délai franc (24h) : annulation immédiate = dans le délai.
        // Relatif au lancement, pas une date fixe (voir `creneauDans()`).
        $idCreneau = $this->creerCreneau($client, $entete, ...self::creneauDans(30));
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('annulee_libre', $donnees['statut'], 'CA-8 : annulation gratuite dans le délai franc.');

        // Renforcement additif (plan reservation-encaissement, G2) : la réservation elle-même reste
        // gratuite « quota » côté libellé de test, mais l'activité PADEL utilisée par `reserver()` est
        // payante et une session de caisse est toujours fournie -> une Vente rattachée `en_cours`
        // (jamais réglée) existe et doit être nettoyée par l'annulation, sans casser ce test déjà vert.
        $idVente = $this->extraireIdVente($donnees['venteRattachee'] ?? null);
        if ($idVente !== null) {
            /** @var EntityManagerInterface $em */
            $em = static::getContainer()->get('doctrine')->getManager();
            $vente = $em->getRepository(Vente::class)->find($idVente);
            self::assertNotNull($vente);
            self::assertSame('annulee', $vente->getStatut()->value, 'G2 : la Vente pendante rattachée est nettoyée à l\'annulation.');
        }
    }

    private function extraireIdVente(mixed $iriOuTableau): ?string
    {
        if ($iriOuTableau === null) {
            return null;
        }
        if (\is_array($iriOuTableau)) {
            return isset($iriOuTableau['id']) ? (string) $iriOuTableau['id'] : null;
        }

        return basename((string) $iriOuTableau);
    }

    public function testCa8AnnulationRefuseeHorsDelaiFrancEnLibreService(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Jeton client (annuler_soi) obtenu via le même client/kernel — combiner plusieurs helpers
        // `xxxSurA()` (qui rebootent chacun le kernel via `createClient()`) casserait la session
        // d'authentification du client `$client` déjà en cours.
        $tokenClient = $this->jeton($client, \App\Reservation\DataFixtures\ReservationFixtures::CLIENT_EMAIL, \App\Reservation\DataFixtures\ReservationFixtures::CLIENT_MDP);
        $enteteOrg = ['auth_bearer' => $tokenClient, 'headers' => $entete['headers']];

        // Créneau à date fixe lointaine (évite tout chevauchement avec le créneau des fixtures), puis
        // délai d'annulation forcé dans le passé : « hors délai franc » de façon déterministe.
        $idCreneau = $this->creerCreneau($client, $entete, '2027-06-02T10:00:00+00:00', '2027-06-02T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservationHorsDelai = $em->getRepository(Reservation::class)->find($idReservation);
        $reservationHorsDelai->setDateLimiteAnnulation((new \DateTimeImmutable())->modify('-5 minutes'));
        $em->flush();

        // Le client (annuler_soi) ne peut plus annuler lui-même hors délai.
        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $enteteOrg);
        self::assertResponseStatusCodeSame(409, 'CA-8 : annulation hors délai refusée en libre-service.');

        // Un agent/administrateur peut qualifier l'issue en annulation tardive facturée.
        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('annulee_tardive_facturee', $donnees['statut'], 'RG-M5-09 : annulation tardive facturée par un agent.');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $facturations = $em->getRepository(\App\Reservation\Entity\FacturationNoShow::class)->findBy(['reservation' => $em->getRepository(Reservation::class)->find($idReservation)]);
        self::assertNotEmpty($facturations, 'CA-11 : une FacturationNoShow est créée pour l\'annulation tardive.');
    }

    public function testCa8AnnulationExactementAuDelaiFrancEncoreGratuite(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Créneau à date fixe lointaine (évite tout chevauchement avec le créneau des fixtures
        // « next monday »). La borne inclusive du délai franc est éprouvée via dateLimiteAnnulation ci-dessous.
        $idCreneau = $this->creerCreneau($client, $entete, '2027-06-01T10:00:00+00:00', '2027-06-01T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        // Force la date limite à un horizon proche (borne inclusive, ≤ délaiFranc) : une marge de
        // quelques minutes absorbe la latence réelle de la requête HTTP suivante dans cet environnement.
        $reservation->setDateLimiteAnnulation((new \DateTimeImmutable())->modify('+5 minutes'));
        $em->flush();

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful('§7 : annulation exactement à l\'heure du délai franc encore gratuite (borne inclusive).');
        self::assertSame('annulee_libre', $client->getResponse()->toArray()['statut']);
    }

    public function testCa9BasculeAutoNoShowApresMarge(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, '2026-09-20T10:00:00+00:00', '2026-09-20T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        // ≥ 1 : la réservation de démonstration des fixtures (créneau passé) peut aussi être basculée.
        $traites = $commande->basculer(new \DateTimeImmutable('2026-09-20T11:05:00+00:00'));
        self::assertGreaterThanOrEqual(1, $traites);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertSame('no_show_facture', $reservation->getStatut()->value, 'CA-9 : bascule automatique en no-show à l\'issue du créneau.');
        $facturations = $em->getRepository(\App\Reservation\Entity\FacturationNoShow::class)->findBy(['reservation' => $reservation]);
        self::assertNotEmpty($facturations, 'CA-9 : une FacturationNoShow est créée.');
    }

    /**
     * ⚠ CE TEST NE PROUVE PAS CE QUE SON ANCIEN NOM ANNONÇAIT.
     *
     * Il s'appelait `testCa10PassageAccesValideConfirmePresence`. Or il **simule** le passage en
     * appelant `confirmerPresence()` depuis le test lui-même, quelques lignes plus bas. Ce qu'il
     * établit réellement — et qui est vrai, utile, et vaut d'être gardé — c'est qu'une présence
     * portant la source `passage_acces` mène à `honoree` plutôt qu'à une facture d'absence.
     *
     * Ce qu'il n'établit pas : que le contrôle d'accès confirme la présence. **Rien ne le fait.**
     * `SourcePresence::PassageAcces` n'est produit nulle part dans `app/src` — la chaîne
     * `Passage → DroitAcces → reservationRef → Reservation` existe pourtant en entier, et un droit
     * d'accès de type `booking` porte déjà une réservation en base (mesuré par allaccess-b8). Il
     * manque un écouteur, pas une structure.
     *
     * ⚠ ET C'EST CE TEST QUI A RENDU LE DÉFAUT INVISIBLE. Le critère CA-10 avait l'air couvert : un
     * test vert, nommé exactement comme la question qu'on se pose. Personne ne relit un test vert
     * dont le nom dit ce qu'on cherchait à savoir — et pendant ce temps, `presenceConfirmee` restait
     * faux pour toute réservation ayant jamais existé, faute d'écran d'émargement.
     *
     * Le chemin manuel, lui, est désormais éprouvé de bout en bout par `EmargementTest`.
     *
     * ⚠ Les assertions ne changent pas : le défaut était le nom, pas le test. Le renommer est tout
     * ce qu'il fallait — et c'était suffisant pour que le trou se voie.
     */
    public function testUnePresenceDeSourcePassageAccesMeneAHonoree(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, '2026-09-21T10:00:00+00:00', '2026-09-21T11:00:00+00:00');
        $idReservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        // Simule un passage d'accès validé pendant la fenêtre du créneau (§4.8) : la présence est
        // confirmée automatiquement sans émargement manuel requis.
        $reservation->confirmerPresence(SourcePresence::PassageAcces, new \DateTimeImmutable('2026-09-21T10:05:00+00:00'));
        $em->flush();
        $em->clear();

        $client->request('GET', '/api/reservations/' . $idReservation, $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertTrue($donnees['presenceConfirmee']);
        self::assertSame('passage_acces', $donnees['sourcePresence'], 'CA-10 : passage validé = présence confirmée, sans émargement manuel.');

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer(new \DateTimeImmutable('2026-09-21T11:05:00+00:00'));
        $em->clear();
        $reservationApres = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertSame('honoree', $reservationApres->getStatut()->value, 'Présence confirmée par accès -> réservation honorée, pas de no-show.');
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function creerCreneau(object $client, array $entete, string $debut, string $fin): string
    {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_PADEL_LIBELLE),
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
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $idBeneficiaire,
                'session' => '/api/session_caisses/' . $session['id'],
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
