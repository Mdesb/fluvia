<?php

declare(strict_types=1);

namespace App\Tests\Stock\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Stock\StockApiTestCase;

/**
 * **Le contrôle d'écart d'inventaire se déclenche quand aucun seuil n'est réglé** (D52).
 *
 * Avant ce lot, `estSignificatif()` renvoyait `false` faute de seuil configuré : **aucun écart n'était
 * jamais significatif, si grand soit-il**, et le droit `stock.valider_ecart` ne se déclenchait donc
 * jamais. N'importe qui pouvant régulariser une ligne pouvait régulariser n'importe quel montant, sans
 * la validation renforcée que ce droit existe précisément pour exiger. Tout fonctionnait, aucun test ne
 * rougissait : le garde-fou était simplement absent.
 *
 * **Ce cas n'était couvert par aucun test de bout en bout**, et il ne pouvait pas l'être : le seul test
 * d'inventaire existant (`InventaireApiTest`) commence par régler le seuil à 5 % lui-même, ce qui est
 * légitime pour illustrer le flag mais laisse le cas par défaut — celui de tout établissement fraîchement
 * installé, puisque **rien dans le dépôt ne pose jamais de seuil** — entièrement dans l'ombre.
 *
 * Le scénario est volontairement le plus dépouillé possible : un article sans lot, donc un théorique de
 * zéro, et un comptage de cinq unités. L'écart est de +5 sur un parc vide — impossible à qualifier
 * d'anodin — et il doit exiger la validation Responsable.
 */
final class SeuilNonRegleTest extends StockApiTestCase
{
    public function testSansSeuilReglelEcartExigeLaValidationResponsable(): void
    {
        // Opérateur d'inventaire **sans** `valider_ecart` : il peut compter et régulariser l'ordinaire,
        // pas trancher un écart significatif.
        [$client, $entete] = $this->operateurStockSur(SocleFixtures::ETAB_B_NOM, ['gerer', 'inventorier', 'lire']);

        $article = $client->request('POST', '/api/article_stocks', $entete + [
            'json' => [
                'codeEAN' => '4006381333931',
                'libelle' => 'Article sans seuil',
                'unite' => 'piece',
                'prixAchatHT' => '3.0000',
                'tauxTvaAchat' => '20.00',
                'seuilMin' => '0.000',
                'seuilMax' => '0.000',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $inventaire = $client->request('POST', '/api/stock_inventaires', $entete + [
            'json' => ['perimetre' => 'tous'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $ligneId = null;
        foreach ($inventaire['lignes'] ?? [] as $ligne) {
            if (str_ends_with((string) $ligne['articleStock'], $article['id'])) {
                $ligneId = $ligne['id'];
                // Aucun lot : le théorique vaut zéro, et il vient desormais des lots, plus du
                // compteur produit — c'est la meme correction, vue par l'autre bout.
                self::assertSame('0.000', $ligne['quantiteTheorique']);
            }
        }
        self::assertNotNull($ligneId, 'Ligne d\'inventaire de l\'article introuvable.');

        $entetePatch = [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => [
                ContexteEtablissement::HEADER => $entete['headers'][ContexteEtablissement::HEADER],
                'Content-Type' => 'application/merge-patch+json',
            ],
        ];

        $ligneComptee = $client->request('PATCH', '/api/stock/lignes-inventaire/' . $ligneId, $entetePatch + [
            'json' => ['quantiteComptee' => '5.000'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        self::assertSame('5.000', $ligneComptee['ecart']);
        self::assertTrue(
            $ligneComptee['significatif'],
            'Sans seuil regle, un ecart doit etre significatif : un exploitant qui n\'a rien configure '
            . 'n\'a pas decide que tout passait, il n\'a rien decide.',
        );

        $client->request('POST', '/api/stock/lignes-inventaire/' . $ligneId . '/regulariser', $entete + ['json' => []]);

        // **Le controle, enfin actif.** Avant, cette regularisation passait sans rien demander.
        self::assertResponseStatusCodeSame(
            403,
            'Un ecart significatif doit exiger stock.valider_ecart, meme lorsque aucun seuil n\'est regle.',
        );
    }
}
