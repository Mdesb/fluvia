<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Tests\Acces\AccesApiTestCase;

/**
 * FILTRER LES PASSAGES PAR CONTRÔLEUR, ÉQUIPEMENT OU ZONE.
 *
 * Les trois rendaient TOUJOURS une liste vide. Mesuré sur une collection d'une ligne : le total
 * valait 1, et chacun des trois filtres rendait 0.
 *
 * ⚠ LA CAUSE N'ÉTAIT PAS DANS CE MODULE. L'identifiant est un `Uuid`, stocké en `BINARY(16)` : le
 * `SearchFilter` compare la colonne à une chaîne de 36 caractères, ne trouve rien, et ne lève rien.
 * Le même défaut avait été constaté la veille sur les demandes RGPD, dans un module sans rapport —
 * c'est ce second cas qui a montré que la famille était systémique et non un accident local.
 *
 * ⚠ CE QUE ÇA DONNAIT À L'ÉCRAN. Un journal des passages filtré sur un lecteur affichait « aucun
 * passage ne répond à ces filtres » sur un lecteur qui en avait. L'exploitant en conclut que le
 * tourniquet n'a rien enregistré, et cherche une panne matérielle.
 *
 * ⚠ ET LE TÉMOIN NON FILTRÉ N'EST PAS DÉCORATIF. Sans lui, une collection vide pour une autre raison
 * — cloisonnement, fixture absente, permission — rendrait ce test vert en accusant le filtre.
 */
final class FiltrePassageTest extends AccesApiTestCase
{
    public function testLesFiltresParAssociationRetrouventLePassage(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->enregistrerUnPassage($client, $entete);

        $total = $client->request('GET', '/api/passages', $entete)->toArray()['totalItems'] ?? 0;
        self::assertGreaterThan(0, $total, 'témoin : la collection contient bien le passage');

        $attendus = [
            'controleur' => '/api/controleurs/' . $this->idControleur(),
            'equipement' => '/api/equipements/' . $this->idEquipement(),
            'espace' => '/api/espace_acces/' . $this->idEspaceAcces(),
        ];

        foreach ($attendus as $propriete => $iri) {
            $n = $client->request('GET', '/api/passages?' . $propriete . '=' . $iri, $entete)->toArray()['totalItems'] ?? 0;

            self::assertSame(
                $total,
                $n,
                sprintf('le filtre « %s » doit retrouver le passage — un zéro se lit « le lecteur n’a rien enregistré »', $propriete),
            );
        }
    }

    /**
     * UNE VALEUR ILLISIBLE FERME LA COLLECTION, ELLE NE L'OUVRE PAS.
     *
     * Rendre la collection entière à qui se trompe de paramètre montrerait des lignes que personne
     * n'a demandées. Et rendre 200 avec une liste vide sans distinction empêche l'écran de séparer
     * « rien ne correspond » de « ce filtre ne marche pas ».
     */
    public function testUneValeurIllisibleNeRendPasToutLaCollection(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->enregistrerUnPassage($client, $entete);

        $total = $client->request('GET', '/api/passages', $entete)->toArray()['totalItems'] ?? 0;
        self::assertGreaterThan(0, $total, 'témoin : il y a bien quelque chose à ne pas rendre');

        $n = $client->request('GET', '/api/passages?controleur=nimportequoi', $entete)->toArray()['totalItems'] ?? 0;

        self::assertSame(0, $n, 'une valeur illisible ferme la collection');
    }

    /** @param array<string, mixed> $entete */
    private function enregistrerUnPassage(object $client, array $entete): void
    {
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);
        self::assertResponseIsSuccessful();
    }
}
