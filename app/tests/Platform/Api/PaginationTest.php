<?php

declare(strict_types=1);

namespace App\Tests\Platform\Api;

use App\Tests\Vente\VenteApiTestCase;

/**
 * UNE LISTE COUPÉE EN SILENCE.
 *
 * Aucun bloc `pagination` n'était déclaré dans `api_platform.yaml` : les défauts du composant
 * s'appliquaient — 30 lignes par page, et `client_items_per_page` à `false`, donc le client ne
 * pouvait pas relever la limite. Toutes les collections de l'application étaient coupées à 30, et
 * les quelque 350 `itemsPerPage: 100|200|500` écrits dans `frontend/src/api/client.js` n'avaient
 * aucun effet : mesuré sur le réseau, `?itemsPerPage=1` rendait la liste entière.
 *
 * POURQUOI PERSONNE NE POUVAIT LE VOIR. La plus grosse collection de la préproduction compte 15
 * lignes ; celles des fixtures, moins encore. Le défaut n'apparaît qu'au trente-et-unième
 * enregistrement — donc chez le premier vrai client, jamais chez nous. Même code de réponse, même
 * forme, juste moins de lignes.
 *
 * ET IL NE RENDAIT PAS SEULEMENT LES ÉCRANS INCOMPLETS. Sur les prélèvements SEPA, le marquage
 * « déjà rejetée » se calcule en recoupant deux listes chargées séparément : au-delà d'une page,
 * une ligne pourtant rejetée cesse d'être reconnue, l'écran rouvre un rejet dessus, et un second
 * impayé s'ouvre sur la même échéance — un accès bloqué deux fois pour un seul incident.
 *
 * Ce test ne vérifie pas un nombre, il vérifie les deux propriétés dont tout le reste dépend :
 * le client peut choisir la taille de page, et le serveur dit combien de lignes existent.
 */
final class PaginationTest extends VenteApiTestCase
{
    public function testLeClientPeutChoisirLaTailleDePage(): void
    {
        [$client, $entete] = $this->adminSurA();

        $complet = $client->request('GET', '/api/produits', $entete)->toArray();
        $total = (int) ($complet['totalItems'] ?? $complet['hydra:totalItems'] ?? 0);

        // Sans au moins deux produits, demander « deux par page » ne distingue rien : le test
        // passerait sans rien mesurer.
        self::assertGreaterThanOrEqual(2, $total, 'trop peu de produits pour éprouver la pagination');

        $page = $client->request('GET', '/api/produits', $entete + ['query' => ['itemsPerPage' => 1]])->toArray();
        $lignes = $page['member'] ?? $page['hydra:member'] ?? [];

        self::assertCount(
            1,
            $lignes,
            'le paramètre itemsPerPage est ignoré : le client ne contrôle pas la taille de page',
        );
    }

    /**
     * LE SERVEUR DOIT DIRE COMBIEN DE LIGNES EXISTENT.
     *
     * C'est la seule chose qui permet à un écran de transformer « il en manque » en « il en manque,
     * et voici combien ». Aucun plafond, si haut soit-il, ne remplace ce compteur : la ligne
     * suivante disparaîtra toujours sans un mot. Le serveur le renvoyait déjà — personne ne le
     * lisait.
     */
    public function testUneReponsePartielleAnnonceLeTotalReel(): void
    {
        [$client, $entete] = $this->adminSurA();

        $page = $client->request('GET', '/api/produits', $entete + ['query' => ['itemsPerPage' => 1]])->toArray();
        $lignes = $page['member'] ?? $page['hydra:member'] ?? [];
        $total = (int) ($page['totalItems'] ?? $page['hydra:totalItems'] ?? 0);

        self::assertGreaterThan(
            \count($lignes),
            $total,
            'la réponse tronquée ne déclare pas un total supérieur au nombre de lignes rendues',
        );
    }
}
