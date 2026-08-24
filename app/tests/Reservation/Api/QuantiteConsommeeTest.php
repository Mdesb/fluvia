<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\ListeAttente;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ACT-1 / D16 point 1 — « une réservation consomme N unités, pas 1 ».
 *
 * Les deux niveaux de capacité sont couverts, parce que ce sont deux refus différents : la capacité
 * du créneau (RG-M5-01) et la jauge globale de la ressource porteuse (RG-M5-08, CA-14). Avant ce
 * lot, les deux comptaient des lignes ; une table de huit y passait pour une place.
 */
final class QuantiteConsommeeTest extends ReservationApiTestCase
{
    /** Capacité de créneau : 8 passent, 3 ne rentrent plus, 2 rentrent, puis c'est complet. */
    public function testLaQuantiteSeConsommeSurLaCapaciteDuCreneau(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneau($client, $entete, 10);
        $idBeneficiaire = $this->idBeneficiairePayeur();

        $reservation = $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 8);
        self::assertResponseIsSuccessful();
        self::assertSame(8, $reservation['quantity'], 'La réservation porte sa quantité.');

        // Deux places restent : une demande de trois est refusée, et le créneau n'est PAS complet —
        // c'est exactement la distinction qui n'existait pas avant ACT-1.
        $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 3);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Places insuffisantes', $client->getResponse()->toArray(false)['detail'] ?? '');

        $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 2);
        self::assertResponseIsSuccessful();

        // 10/10 : là, c'est bien « complet », et le message d'origine ne bouge pas (la liste
        // d'attente ne se propose que dans ce cas-là).
        $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 1);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('complet', $client->getResponse()->toArray(false)['detail'] ?? '');
    }

    /** Non-régression : sans `quantity`, une réservation vaut une unité, comme avant ACT-1. */
    public function testSansQuantiteUneReservationVautUneUnite(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneau($client, $entete, 2);

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $client->getResponse()->toArray()['quantity']);
    }

    /** Une quantité nulle ou négative est refusée avant d'atteindre la jauge (elle la fausserait). */
    public function testQuantiteNonPositiveRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneau($client, $entete, 10);

        $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur(), 0);
        self::assertResponseStatusCodeSame(422);

        $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur(), -3);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Second niveau (RG-M5-08, CA-14) : la « Salle collective » porte une jauge propre de 12. Un
     * créneau de 20 places ne suffit donc pas — 8 puis 5 dépassent la ressource, pas le créneau.
     */
    public function testLaJaugeDeLaRessourceCompteAussiEnUnites(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneau($client, $entete, 20);
        $idBeneficiaire = $this->idBeneficiairePayeur();

        $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 8);
        self::assertResponseIsSuccessful();

        $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 5);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Jauge globale', $client->getResponse()->toArray(false)['detail'] ?? '');

        // 12 - 8 = 4 : quatre passent, la borne est bien la jauge et pas un arrondi.
        $this->reserver($client, $entete, $idCreneau, $idBeneficiaire, 4);
        self::assertResponseIsSuccessful();
    }

    /**
     * Liste d'attente : on attend pour N unités, et une promotion ne rend pas huit couverts à qui
     * n'a que quatre places à offrir. Le rang reste **en attente** plutôt que d'être sauté —
     * arbitrage ouvert à claude-A ; ce test verrouille le choix actuel pour qu'il soit visible s'il
     * change.
     */
    public function testLaListeDAttentePorteSaQuantiteEtNeSautePasUnRang(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneau($client, $entete, 4);

        $reservation = $this->reserver($client, $entete, $idCreneau, $this->idBeneficiairePayeur(), 4);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/reservation/creneaux/' . $idCreneau . '/liste-attente', $entete + [
            'json' => ['beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiaireParPrenom(CrmFixtures::ENFANT_PRENOM), 'quantity' => 8],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(8, $client->getResponse()->toArray()['quantity']);

        // Le désistement libère quatre unités : le premier de la liste en demande huit, personne
        // n'est promu et il garde son rang.
        $client->request('POST', '/api/reservation/reservations/' . $reservation['id'] . '/annuler', $entete);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $inscription = $em->getRepository(ListeAttente::class)->findOneBy(['rang' => 1]);
        self::assertNotNull($inscription);
        self::assertSame('en_attente', $inscription->getStatut()->value, 'Un groupe qui ne rentre pas ne doit pas etre promu.');
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
    private function creerCreneau(object $client, array $entete, int $capacite): string
    {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_SALLE_LIBELLE),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE),
                'debut' => '2026-09-11T14:00:00+00:00',
                'fin' => '2026-09-11T15:00:00+00:00',
                'capacite' => $capacite,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
