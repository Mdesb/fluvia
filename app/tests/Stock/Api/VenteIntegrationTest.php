<?php

declare(strict_types=1);

namespace App\Tests\Stock\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Stock\DataFixtures\StockFixtures;
use App\Stock\Entity\MouvementStock;
use App\Tests\Stock\StockApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Intégration M2 ↔ App\Stock (§3.2 du plan, RG-STOCK-01/17) : rupture de stock bloque toujours la
 * vente sans créer de mouvement (CA-10, réutilise RG-M2-04) ; une vente validée avec une ligne
 * d'article de stock déclenche automatiquement un `MouvementStock(sortie_vente)` référencé à la
 * `LigneVente`, avec le coût FIFO au moment de la validation (CA-11) — sans jamais réécrire
 * `off_stock.disponibilite` une seconde fois (non-double-décrément, `SortieVenteStockSubscriber`).
 */
final class VenteIntegrationTest extends StockApiTestCase
{
    public function testCa10RuptureBloqueVenteSansMouvement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $article = $this->creerEtRattacherArticle($client, $entete);
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);

        // Disponibilité encore à 0 (aucune réception) : ajout refusé (RG-M2-04/RG-STOCK-11).
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduitBoutique(),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseStatusCodeSame(422);

        $mouvements = $client->request('GET', '/api/stock_mouvements', $entete + [
            'query' => ['articleStock' => $article['id']],
        ])->toArray();
        self::assertCount(0, $mouvements['member'] ?? $mouvements['hydra:member'] ?? [], 'CA-10 : aucun mouvement créé pour une vente refusée.');
    }

    public function testCa11VenteValideeCreeUnMouvementSortieVenteEtNeDecrementeQuUneFois(): void
    {
        [$client, $entete] = $this->adminSurA();
        $article = $this->creerEtRattacherArticle($client, $entete);
        $this->receptionner($client, $entete, $article['id'], '10.000', '5.0000');

        self::assertSame(10, $this->disponibilite($client, $entete));

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $ligneReponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduitBoutique(),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(),
                'quantite' => 3,
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        $ligneId = $ligneReponse['lignes'][0]['id'];

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '24.00']]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        // Décrément M2 unique : 10 − 3 = 7 (le listener App\Stock ne réécrit jamais la disponibilité).
        self::assertSame(7, $this->disponibilite($client, $entete), 'Non-double-décrément : une seule écriture sur off_stock.disponibilite.');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $mouvement = $em->getRepository(MouvementStock::class)->findOneBy(['referenceType' => 'LigneVente', 'referenceId' => $ligneId]);
        self::assertNotNull($mouvement, 'CA-11 : mouvement sortie_vente journalisé automatiquement.');
        self::assertSame('sortie_vente', $mouvement->getType()->value);
        self::assertSame('3.000', $mouvement->getQuantite());
        self::assertSame('5.0000', $mouvement->getCoutUnitaireCalcule());
        self::assertSame('15.00', $mouvement->getCoutTotalCalcule());
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function creerEtRattacherArticle(object $client, array $entete): array
    {
        $article = $client->request('POST', '/api/article_stocks', $entete + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM),
                'codeEAN' => '5901234123457',
                'libelle' => 'Mug boutique',
                'unite' => 'piece',
                'prixAchatHT' => '5.0000',
                'tauxTvaAchat' => '20.00',
                'seuilMin' => '2.000',
                'seuilMax' => '20.000',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $produit = $this->entite(Produit::class, ['libelleRecherche' => StockFixtures::PRODUIT_BOUTIQUE]);
        $client->request('POST', '/api/stock/articles/' . $article['id'] . '/rattacher-produit', $entete + [
            'json' => ['produit' => (string) $produit->getId()],
        ]);
        self::assertResponseIsSuccessful();

        return $article;
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function receptionner(object $client, array $entete, string $articleId, string $quantite, string $prix): void
    {
        $etabIri = '/api/etablissements/' . $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM);
        $fournisseur = $client->request('POST', '/api/stock_fournisseurs', $entete + [
            'json' => ['etablissement' => $etabIri, 'raisonSociale' => 'Grossiste Boutique SARL'],
        ])->toArray();

        $reception = $client->request('POST', '/api/stock_reception_achats', $entete + [
            'json' => [
                'etablissement' => $etabIri,
                'fournisseur' => '/api/stock_fournisseurs/' . $fournisseur['id'],
                'date' => '2026-03-01',
                'numeroBonLivraison' => 'BL-INIT',
            ],
        ])->toArray();

        $client->request('POST', '/api/stock_ligne_reception_achats', $entete + [
            'json' => [
                'reception' => '/api/stock_reception_achats/' . $reception['id'],
                'articleStock' => '/api/article_stocks/' . $articleId,
                'quantiteRecue' => $quantite,
                'prixAchatUnitaireHT' => $prix,
            ],
        ]);

        $client->request('POST', '/api/stock/receptions-achat/' . $reception['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function disponibilite(object $client, array $entete): int
    {
        $reponse = $client->request('GET', '/api/produits/' . $this->idProduitBoutique(), $entete)->toArray();

        return (int) ($reponse['stock']['disponibilite'] ?? -1);
    }

    private function idProduitBoutique(): string
    {
        return (string) $this->entite(Produit::class, ['libelleRecherche' => StockFixtures::PRODUIT_BOUTIQUE])->getId();
    }

    private function idTarif(): string
    {
        return (string) $this->entite(\App\Offre\Entity\TypeTarif::class, ['nom' => OffreFixtures::TARIF_PLEIN])->getId();
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function ouvrirSession(object $client, array $entete): array
    {
        return $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + [
            'json' => [
                'pointDeVente' => '/api/point_de_ventes/' . $this->idPointDeVente(),
                'caisse' => '/api/caisses/' . $this->idCaisse(),
                'regisseur' => '/api/utilisateurs/' . $this->idAdmin(),
                'codeRegisseur' => 'CODE-REGIE-2026',
                'fondDeCaisse' => '50.00',
            ],
        ])->toArray();
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function creerVente(object $client, array $entete, string $sessionId): array
    {
        return $client->request('POST', '/api/ventes', $entete + [
            'json' => ['session' => '/api/session_caisses/' . $sessionId],
        ])->toArray();
    }

    private function idPointDeVente(): string
    {
        return (string) $this->entite(\App\Caisse\Entity\PointDeVente::class, ['libelle' => \App\Vente\DataFixtures\VenteFixtures::PDV_LIBELLE])->getId();
    }

    private function idCaisse(): string
    {
        return (string) $this->entite(\App\Caisse\Entity\Caisse::class, ['libelle' => \App\Vente\DataFixtures\VenteFixtures::CAISSE_LIBELLE])->getId();
    }

    private function idAdmin(): string
    {
        return (string) $this->entite(\App\Securite\Entity\Utilisateur::class, ['email' => \App\DataFixtures\SocleFixtures::ADMIN_EMAIL])->getId();
    }
}
