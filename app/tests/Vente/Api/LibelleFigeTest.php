<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Tests\Vente\VenteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **Un ticket dit ce que le produit s'appelait le jour de la vente, pas ce qu'il s'appelle aujourd'hui.**
 *
 * `LigneVente::$libelleProduit` est une **copie datée**, pas une référence. Quelqu'un verra un jour
 * `libelle` sur `LigneVente` et `libelle` sur `Produit`, et proposera de « normaliser » en remplaçant
 * la copie par une jointure. Ce test est là pour que cette proposition **échoue bruyamment** — un test
 * qui casse est plus difficile à supprimer qu'un commentaire qu'on ne lit pas.
 *
 * Ce qui serait perdu : tous les tickets déjà émis mentiraient rétroactivement dès qu'un article
 * change de nom, et un duplicata tiré six mois plus tard n'aurait plus rien à voir avec l'original.
 * C'est la même règle que `prixUnitaire`, stocké et jamais recalculé, et que `optionsSelectionnees`,
 * figé par RG-OPT-09.
 */
final class LibelleFigeTest extends VenteApiTestCase
{
    /** **Le test qui porte la décision** : on renomme le produit, le ticket d'hier ne bouge pas. */
    public function testRenommerLeProduitNeChangePasUnTicketDejaEmis(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->venteValidee($client, $entete, $session['id']);

        $avant = $client->request('POST', '/api/ventes/' . $vente['id'] . '/ticket', $entete + [
            'json' => ['mode' => 'imprimer'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotSame([], $avant['lignes'], 'Un ticket sans lignes n\'est pas un ticket.');
        $libelleVendu = $avant['lignes'][0]['libelle'];
        self::assertNotNull($libelleVendu);

        // Le catalogue bouge : nouvelle saison, nouveau nom commercial. Cas parfaitement ordinaire.
        $em = $this->em();
        $produit = $em->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);
        self::assertNotNull($produit);
        $produit->setLibelle(['fr' => 'Entrée unitaire — ancienne formule (retirée)']);
        $em->flush();
        $em->clear();

        $apres = $client->request('POST', '/api/ventes/' . $vente['id'] . '/ticket', $entete + [
            'json' => ['mode' => 'duplicata'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        self::assertSame(
            $libelleVendu,
            $apres['lignes'][0]['libelle'],
            'Le duplicata doit dire ce qui a été vendu, pas ce que le catalogue affiche aujourd\'hui.',
        );
        self::assertTrue($apres['duplicata'], 'Un second tirage est un duplicata, et il doit se déclarer comme tel.');
    }

    /**
     * Le ticket est reconstituable **sans le panier** — c'est-à-dire réellement.
     *
     * Avant, `TicketProcessor` ne renvoyait ni libellé, ni quantité, ni montant : le document
     * n'existait que dans l'onglet du caissier, et `claude-H` refusait de proposer une réimpression
     * plutôt que de promettre un duplicata vide.
     */
    public function testLeTicketPorteSonContenu(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->venteValidee($client, $entete, $session['id']);

        $ticket = $client->request('POST', '/api/ventes/' . $vente['id'] . '/ticket', $entete + [
            'json' => ['mode' => 'imprimer'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        self::assertSame($vente['numero'], $ticket['numero']);
        self::assertSame($vente['total'], $ticket['total']);
        self::assertCount(1, $ticket['lignes']);

        $ligne = $ticket['lignes'][0];
        foreach (['libelle', 'tarif', 'quantite', 'prixUnitaire', 'montantLigne'] as $champ) {
            self::assertArrayHasKey($champ, $ligne, sprintf('Un ticket sans « %s » ne se relit pas.', $champ));
        }
        self::assertNotNull($ligne['tarif'], 'Le tarif appliqué fait partie de ce qu\'on doit pouvoir défendre.');
        self::assertSame(1, $ligne['quantite']);
    }

    /** Le libellé est gravé à la création de la ligne, y compris hors caisse (vente directe). */
    public function testLeLibelleEstGraveMemeSansSession(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $vente = $client->request('POST', '/api/ventes', $entete + ['json' => ['origineHorsLigne' => false]])->toArray();
        $this->ajouterLigne($client, $entete, $vente['id']);

        $relue = $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray();
        self::assertNotNull($relue['lignes'][0]['libelleProduit'] ?? null);
        self::assertNotNull($relue['lignes'][0]['libelleTypeTarif'] ?? null);
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function venteValidee(object $client, array $entete, string $sessionId): array
    {
        $vente = $this->creerVente($client, $entete, $sessionId);
        $this->ajouterLigne($client, $entete, $vente['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'cb']]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray();
    }

    /** @param array<string, mixed> $entete */
    private function ajouterLigne(object $client, array $entete, string $venteId): void
    {
        $client->request('POST', '/api/ventes/' . $venteId . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
