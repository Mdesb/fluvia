<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Reservation\DataFixtures\ReservationFixtures;
use App\Tests\Reservation\ReservationApiTestCase;

/**
 * `GET /reservation/ressources/{id}/occupation?du=…&au=…` — ce qui se passe sur une ressource
 * pendant une plage (#34).
 *
 * ── POURQUOI CE FICHIER EXISTE ────────────────────────────────────────────────────────────────
 *
 * `OccupancyProvider` et son DTO étaient écrits, documentés et injectés depuis leur création, et
 * **aucune route n'y menait**. Un fournisseur que rien n'appelle ne casse jamais : il n'y avait
 * donc rien à voir, et le docblock décrivait au présent une route qui n'était déclarée nulle part.
 *
 * ⚠ CE QUE CE TEST DOIT PROUVER N'EST PAS QUE LA ROUTE RÉPOND, C'EST QUE SON CHIFFRE EST JUSTE.
 * Une route branchée qui rendrait « 20 restantes » sur un créneau complet serait pire que
 * l'absence : l'écran cesserait de chercher. Chaque assertion est donc précédée de son témoin —
 * on regarde le MÊME créneau avant et après une réservation, et c'est l'écart qui porte la preuve.
 */
final class OccupationRessourceTest extends ReservationApiTestCase
{
    public function testLOccupationSuitLesReservationsReellesEtNonLaCapaciteAffichee(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();

        $idRessource = $this->idRessource(ReservationFixtures::RESSOURCE_BASSIN_LIBELLE);

        // Une plage isolée, loin de tout ce que les fixtures posent : ce test lit une ressource
        // PARTAGÉE, donc il ne doit compter que ce qu'il a lui-même créé. Une date relative — ou
        // proche de « lundi prochain » — l'exposerait au défaut daté corrigé le 07/09.
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $idRessource,
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE),
                'debut' => '2027-03-15T09:00:00+00:00',
                'fin' => '2027-03-15T10:00:00+00:00',
                'capacite' => 5,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idCreneau = $client->getResponse()->toArray()['id'];

        $route = '/api/reservation/ressources/' . $idRessource . '/occupation?du=2027-03-15&au=2027-03-15';

        // ── TÉMOIN : la route répond, et voit le créneau qu'on vient de poser ──────────────────
        $avant = $client->request('GET', $route, $entete)->toArray();
        self::assertResponseIsSuccessful('La route doit exister — elle n\'était câblée nulle part avant #34.');
        $ligneAvant = $this->ligneDu($avant, $idCreneau);
        self::assertSame(5, $ligneAvant['capacite']);
        self::assertSame(0, $ligneAvant['occupees'], 'Aucune réservation encore : le témoin part de zéro.');
        self::assertSame(5, $ligneAvant['restantes']);

        // ── L'ÉCART EST LA PREUVE ─────────────────────────────────────────────────────────────
        //
        // ⚠ DEUX ACTEURS, ET CE N'EST PAS UNE COMPLICATION GRATUITE. Le gestionnaire de planning
        // porte `gerer_creneau` mais PAS `reserver` ; l'agent d'accueil l'inverse. Faire les deux
        // gestes avec un compte tout-puissant aurait masqué cette séparation — et le jour où elle
        // bouge, ce test continuerait de passer en ne mesurant plus le parcours réel.
        [$agent, $enteteAgent] = $this->agentSurA();
        $agent->request('POST', '/api/reservation/reservations', $enteteAgent + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);
        self::assertResponseIsSuccessful();

        $apres = $client->request('GET', $route, $entete)->toArray();
        $ligneApres = $this->ligneDu($apres, $idCreneau);
        self::assertSame(5, $ligneApres['capacite'], 'La capacité ne bouge pas : c\'est l\'occupation qui bouge.');
        self::assertSame(1, $ligneApres['occupees'], 'La réservation est comptée par le serveur, pas déduite par l\'écran.');
        self::assertSame(4, $ligneApres['restantes']);
    }

    /**
     * ⚠ LA PLAGE EST INCLUSIVE DES DEUX CÔTÉS, et c'est le genre de détail qui fait disparaître
     * une journée entière sans que personne ne le remarque sur un mois qui en affiche vingt-neuf.
     */
    public function testLeDernierJourDeLaPlageEstCompris(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();

        $idRessource = $this->idRessource(ReservationFixtures::RESSOURCE_SALLE_LIBELLE);
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $idRessource,
                'debut' => '2027-04-30T18:00:00+00:00',
                'fin' => '2027-04-30T19:00:00+00:00',
                'capacite' => 3,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idCreneau = $client->getResponse()->toArray()['id'];

        // Témoin : une plage qui s'arrête la veille ne le voit pas.
        $veille = $client->request('GET', '/api/reservation/ressources/' . $idRessource . '/occupation?du=2027-04-01&au=2027-04-29', $entete)->toArray();
        self::assertNull($this->chercher($veille, $idCreneau), 'Un créneau hors plage ne doit pas apparaître.');

        // Le jour de fin lui-même est compris, entier.
        $incluse = $client->request('GET', '/api/reservation/ressources/' . $idRessource . '/occupation?du=2027-04-01&au=2027-04-30', $entete)->toArray();
        self::assertNotNull($this->chercher($incluse, $idCreneau), '« du 1er au 30 » comprend le 30 en entier.');
    }

    public function testUneDateIllisibleEstRefuseeAuLieuDEtreInterpretee(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $idRessource = $this->idRessource(ReservationFixtures::RESSOURCE_SALLE_LIBELLE);

        // Retomber sur « aujourd'hui » afficherait un mois qui n'est pas celui qu'on regarde, sans
        // rien signaler — l'écran aurait l'air juste.
        $client->request('GET', '/api/reservation/ressources/' . $idRessource . '/occupation?du=15-03-2027&au=2027-03-16', $entete);
        self::assertResponseStatusCodeSame(422);
    }

    /** @param array<string, mixed> $reponse @return array<string, mixed> */
    private function ligneDu(array $reponse, string $idCreneau): array
    {
        $ligne = $this->chercher($reponse, $idCreneau);
        self::assertNotNull($ligne, 'Créneau absent de la réponse d\'occupation.');

        return $ligne;
    }

    /** @param array<string, mixed> $reponse @return array<string, mixed>|null */
    private function chercher(array $reponse, string $idCreneau): ?array
    {
        foreach ($reponse['creneaux'] ?? [] as $ligne) {
            if (($ligne['creneau'] ?? null) === $idCreneau) {
                return $ligne;
            }
        }

        return null;
    }
}
