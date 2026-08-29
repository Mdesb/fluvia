<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Tests\Crm\CrmApiTestCase;

/**
 * FILTRER LES DEMANDES RGPD PAR CLIENT.
 *
 * Mesuré sur la préproduction par une autre session, sur une collection qui contenait bien deux
 * demandes du même client :
 *
 *     ?statut=recue   -> 200, total 2      la colonne scalaire répond
 *     ?client=<IRI>   -> 200, total 0      l'association ne répond pas
 *
 * Les deux propriétés sont pourtant déclarées dans LA MÊME annotation `#[ApiFilter(SearchFilter…)]`.
 *
 * ⚠ CE QUE ÇA AURAIT DONNÉ SUR CET ÉCRAN-LÀ. Ouvert depuis la fiche de quelqu'un, il aurait affiché
 * « cette personne n'a jamais demandé l'effacement de ses données » alors qu'elle en a deux en cours
 * — sur le seul écran de l'application qui porte un délai légal d'un mois.
 *
 * ⚠ ET LE TÉMOIN SUR LE STATUT N'EST PAS DÉCORATIF. Sans lui, une collection vide pour une autre
 * raison — cloisonnement, fixture absente, permission — rendrait ce test vert en accusant le mauvais
 * mécanisme. Il faut prouver que la collection contient bien les demandes AVANT de prouver que le
 * filtre les retrouve.
 */
final class FiltreDemandeRgpdTest extends CrmApiTestCase
{
    public function testLeFiltreParClientRetrouveLesDemandesDeCeClient(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        foreach (['anonymisation', 'effacement'] as $type) {
            $client->request('POST', '/api/demande_rgpds', $entete + [
                'json' => ['client' => '/api/clients/' . $payeurId, 'type' => $type],
            ]);
            self::assertResponseIsSuccessful();
        }

        // ── LE TÉMOIN ───────────────────────────────────────────────────────────────────────────
        // La collection non filtrée doit contenir les deux demandes. Sans cette vérification, un
        // total nul plus bas accuserait le filtre pour une cause qui serait ailleurs.
        $toutes = $client->request('GET', '/api/demande_rgpds', $entete)->toArray();
        self::assertSame(2, $toutes['totalItems'] ?? 0, 'témoin : les deux demandes sont bien lisibles');

        $parClient = $client->request('GET', '/api/demande_rgpds?client=/api/clients/' . $payeurId, $entete)->toArray();

        self::assertSame(
            2,
            $parClient['totalItems'] ?? 0,
            'le filtre par client doit retrouver les demandes de ce client — un zéro ici se lit « cette personne n’a rien demandé »',
        );
    }

    /**
     * ET LE FILTRE DISCRIMINE VRAIMENT.
     *
     * Sans ce second cas, un filtre entièrement ignoré — qui rendrait donc la collection complète —
     * passerait le premier test avec les félicitations.
     */
    public function testLeFiltreParClientNeRendPasTouteLaCollection(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        $client->request('POST', '/api/demande_rgpds', $entete + [
            'json' => ['client' => '/api/clients/' . $payeurId, 'type' => 'anonymisation'],
        ]);
        self::assertResponseIsSuccessful();

        $autre = $client->request('POST', '/api/clients', $entete + [
            'json' => ['type' => 'physique', 'nom' => 'Témoin', 'prenom' => 'Filtre', 'email' => 'temoin.filtre@example.test'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/demande_rgpds', $entete + [
            'json' => ['client' => '/api/clients/' . $autre['id'], 'type' => 'effacement'],
        ]);
        self::assertResponseIsSuccessful();

        $toutes = $client->request('GET', '/api/demande_rgpds', $entete)->toArray();
        self::assertSame(2, $toutes['totalItems'] ?? 0, 'témoin : deux demandes, deux clients');

        $parClient = $client->request('GET', '/api/demande_rgpds?client=/api/clients/' . $payeurId, $entete)->toArray();

        self::assertSame(1, $parClient['totalItems'] ?? 0, 'le filtre retient la demande du client visé, et elle seule');
    }
}
