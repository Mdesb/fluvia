<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\ComplementaryProduct;
use App\Offre\Entity\Produit;
use App\Offre\Enum\ComplementMode;
use App\Tests\Vente\VenteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UN COMPLÉMENT OBLIGATOIRE EMPÊCHE LA VALIDATION, ET RIEN D'AUTRE NE L'EMPÊCHE.
 *
 * Première des neuf typologies de produits listées par Maxime le 30/08, et la seule dont rien
 * n'existait. Le complément est un **produit entier** — sa TVA, son compte, sa grille tarifaire, son
 * billet éventuel — parce qu'une option d'`App\OptionProduit` modifie le prix unitaire de la ligne
 * du parent et hériterait donc de sa catégorie comptable : une serviette à 20 % vendue en option
 * d'une entrée à 5,5 % serait comptabilisée à 5,5 %.
 *
 * ── LES DEUX MOITIÉS, ET LA SECONDE EST LE TÉMOIN ──────────────────────────────────────────────
 *
 * « La vente est refusée sans le complément » ne prouve rien tout seul : elle serait vraie d'une
 * vente refusée pour n'importe quelle autre raison — un reste dû, un point de vente absent, une
 * session fermée. Le cas qui donne son sens au premier est celui où le complément EST là et où la
 * vente passe.
 *
 * ⚠ ET LE MESSAGE COMPTE AUTANT QUE LE REFUS. Un 422 muet à la caisse laisse l'agent devant un
 * client sans savoir quoi ajouter : on vérifie que le libellé du complément manquant y figure.
 */
final class ComplementObligatoireTest extends VenteApiTestCase
{
    public function testUneVenteSansSonComplementObligatoireEstRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->lier(ComplementMode::Required);

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);

        $this->ajouter($client, $entete, $vente['id'], OffreFixtures::PRODUIT_ENTREE);
        $client->request('POST', '/api/ventes/'.$vente['id'].'/paiements', $entete + ['json' => ['moyen' => 'cb']]);

        $client->request('POST', '/api/ventes/'.$vente['id'].'/valider', $entete + ['json' => []]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            OffreFixtures::PRODUIT_CARTE,
            $client->getResponse()->getContent(false),
            'Le refus doit NOMMER le complément manquant : un 422 muet laisse l’agent sans savoir quoi ajouter.',
        );
    }

    public function testLaMemeVenteAvecLeComplementPasse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->lier(ComplementMode::Required);

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);

        $this->ajouter($client, $entete, $vente['id'], OffreFixtures::PRODUIT_ENTREE);
        $this->ajouter($client, $entete, $vente['id'], OffreFixtures::PRODUIT_CARTE);
        $client->request('POST', '/api/ventes/'.$vente['id'].'/paiements', $entete + ['json' => ['moyen' => 'cb']]);

        $client->request('POST', '/api/ventes/'.$vente['id'].'/valider', $entete + ['json' => []]);

        // ── LE TÉMOIN ───────────────────────────────────────────────────────────────────────────
        // Sans lui, le refus du cas précédent serait indiscernable d'une vente qui ne se valide
        // jamais, pour une raison qui n'a rien à voir avec les compléments.
        self::assertResponseIsSuccessful('Témoin : avec son complément, la même vente doit passer.');
    }

    /**
     * `suggere` propose, il n'impose pas — et c'est le cas qu'on casserait sans s'en apercevoir.
     *
     * ⚠ Une garde qui refuserait TOUS les compléments manquants, quel que soit leur mode, passerait
     * les deux tests ci-dessus. Celui-ci est le seul qui distingue « bloque quand c'est obligatoire »
     * de « bloque toujours ».
     */
    public function testUnComplementSuggereNeBloqueRien(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->lier(ComplementMode::Suggested);

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);

        $this->ajouter($client, $entete, $vente['id'], OffreFixtures::PRODUIT_ENTREE);
        $client->request('POST', '/api/ventes/'.$vente['id'].'/paiements', $entete + ['json' => ['moyen' => 'cb']]);

        $client->request('POST', '/api/ventes/'.$vente['id'].'/valider', $entete + ['json' => []]);

        self::assertResponseIsSuccessful('Un complément suggéré est proposé, jamais imposé.');
    }

    // ── Aides ───────────────────────────────────────────────────────────────────────────────────

    private function lier(ComplementMode $mode): void
    {
        $em = $this->em();

        $lien = (new ComplementaryProduct())
            ->setProduct($this->produit(OffreFixtures::PRODUIT_ENTREE))
            ->setComplement($this->produit(OffreFixtures::PRODUIT_CARTE))
            ->setMode($mode);

        $em->persist($lien);
        $em->flush();
    }

    private function produit(string $libelle): Produit
    {
        $product = $this->em()->getRepository(Produit::class)->find($this->idProduit($libelle));
        self::assertInstanceOf(Produit::class, $product, 'produit de fixture introuvable : '.$libelle);

        return $product;
    }

    /** @param array<string, mixed> $entete */
    private function ajouter(object $client, array $entete, string $venteId, string $libelleProduit): void
    {
        $client->request('POST', '/api/ventes/'.$venteId.'/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/'.$this->idProduit($libelleProduit),
                'typeTarif' => '/api/type_tarifs/'.$this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful('ajout de ligne : '.$libelleProduit);
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
