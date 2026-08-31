<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Stock;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Une saisie qui contredit le type est REFUSÉE — et ce qui existait déjà n'est jamais détruit.
 *
 * ── CE QUE CE FICHIER A DÉCRIT AVANT, ET POURQUOI IL A CHANGÉ ──────────────────────────────────
 *
 * Ce test s'appelait `FacettePurgeSilencieuseTest` et il fixait un DÉFAUT : `ProduitProcessor`
 * purgeait à chaque enregistrement, si bien que modifier la **couleur de caisse** d'un produit lui
 * faisait perdre son stock — réponse 200, aucun message, aucune trace. Il était écrit pour échouer
 * le jour où quelqu'un corrigerait. C'est arrivé le 30/08 ; le voici réécrit.
 *
 * Ce qui rendait la correction indécidable, c'est qu'on ignorait où vivait une jauge : refuser un
 * stock sur une entrée aurait rendu « Place limitée, 200 places » inexprimable. Maxime a tranché —
 * **la capacité vit sur l'événement**, parce que plusieurs produits (plein, réduit, scolaire)
 * doivent décompter le MÊME compteur : sinon vendre 150 pleins et 60 réduits met 210 personnes dans
 * une salle de 200. Un stock sur une entrée unitaire est donc une erreur de modèle, et la refuser ne
 * rend plus rien impossible.
 *
 * ── LES DEUX MOITIÉS DE LA RÈGLE, ET LA SECONDE EST CELLE QU'ON OUBLIE ─────────────────────────
 *
 * 1. Une saisie contradictoire est refusée, avec un message qui nomme le type et le champ.
 * 2. Une donnée contradictoire DÉJÀ EN BASE est laissée en place, et le produit reste modifiable.
 *
 * Sans la seconde, le remède serait pire que le mal : le silence détruisait une donnée, un refus
 * total bloquerait le produit — on ne pourrait plus corriger son libellé tant que personne n'aurait
 * réparé la donnée par un autre chemin.
 */
final class FacetteContradictoireTest extends OffreApiTestCase
{
    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /**
     * ⚠ `$a + $b` GARDE LA GAUCHE. Écrire `$entete + ['headers' => …]` sur un `$entete` qui porte
     * déjà `headers` jette silencieusement le `Content-Type` — le PATCH part alors en `ld+json` et
     * API Platform le refuse en 415, avec un message qui parle de types MIME et pas du tableau.
     * D'où cette fonction : un seul endroit qui compose l'en-tête, personne n'a à s'en souvenir.
     *
     * @return array<string, mixed>
     */
    private function patch(string $token, string $idEtablissement): array
    {
        return [
            'auth_bearer' => $token,
            'headers' => [
                ContexteEtablissement::HEADER => $idEtablissement,
                'Content-Type' => 'application/merge-patch+json',
            ],
        ];
    }

    /** Un stock écrit sur un type qui ne le déclare pas : refusé, et le message dit pourquoi. */
    public function testStockEcritSurUnTypeSansFacetteEstRefuse(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $idEntree = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);

        // `entree_unitaire` déclare ["billet","consommateur"] — pas `stock`.
        $client->request('PATCH', '/api/produits/'.$idEntree, $this->patch($token, $idA) + [
            'json' => ['stock' => ['type' => 'dedie', 'disponibilite' => 40]],
        ]);

        self::assertResponseStatusCodeSame(422);

        // ⚠ LE MESSAGE EST LA MOITIÉ QUI COMPTE. Un 422 muet laisse l'exploitant devant un champ
        // qui refuse sans dire où poser ce qu'il voulait poser. On vérifie donc qu'il nomme le type
        // ET qu'il indique la destination — l'événement pour une jauge, la boutique pour du stock.
        $corps = $client->getResponse()->getContent(false);
        self::assertStringContainsString('Entrée unitaire', $corps, 'Le message doit nommer le type refusant.');
        self::assertStringContainsString('boutique', $corps, 'Le message doit dire où va un stock de marchandise.');
        self::assertStringContainsString('événement', $corps, "Le message doit dire où va la jauge d'un événement.");

        // Et rien n'a été écrit.
        $this->em()->clear();
        /** @var Produit $produit */
        $produit = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);
        self::assertNull($produit->getStock());
    }

    /**
     * Un stock DÉJÀ EN BASE survit à la modification d'un champ sans rapport.
     *
     * ⚠ C'est le témoin qui distingue « la saisie contradictoire est refusée » de « tout produit
     * portant une donnée contradictoire est bloqué ». Sans lui, un refus total passerait le premier
     * test — et rendrait inmodifiables les deux produits de la préprod (`PRD-PLACE01`,
     * `PRD-CADENAS01`) qui portent précisément un stock que leur type ne déclare pas.
     */
    public function testUnStockDejaEnBaseSurvitAUneModificationSansRapport(): void
    {
        [$client, $token, $idA] = $this->adminSurA();

        // On pose le stock par l'ORM, comme l'ont fait la fixture et l'import qui ont produit
        // PRD-PLACE01 : la voie API est justement celle que le premier test montre refusée.
        /** @var Produit $produit */
        $produit = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);
        $stock = (new Stock())->setType('dedie')->setDisponibilite(7);
        $produit->setStock($stock);
        $this->em()->persist($stock);
        $this->em()->flush();

        $idEntree = (string) $produit->getId();
        $this->em()->clear();

        // Témoin positif : le stock est bien là AVANT. Sans cette assertion, un test vert ne
        // distinguerait pas « le stock a survécu » de « le stock n'a jamais été posé ».
        /** @var Produit $avant */
        $avant = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);
        self::assertNotNull($avant->getStock(), 'Le stock doit exister avant la modification.');
        self::assertSame(7, $avant->getStock()->getDisponibilite());

        // Une modification qui ne parle pas de stock du tout.
        $client->request('PATCH', '/api/produits/'.$idEntree, $this->patch($token, $idA) + [
            'json' => ['couleurCaisse' => '#123456'],
        ]);
        self::assertResponseIsSuccessful('Le produit doit rester modifiable malgré sa donnée héritée.');

        $this->em()->clear();
        /** @var Produit $apres */
        $apres = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);

        self::assertSame('#123456', $apres->getCouleurCaisse(), 'La couleur demandée est enregistrée.');
        self::assertNotNull(
            $apres->getStock(),
            "⚠ Le stock a disparu en changeant une couleur — c'est le défaut du 30/08, revenu.",
        );
        self::assertSame(7, $apres->getStock()->getDisponibilite());
    }

    /**
     * Le témoin de non-régression du chemin normal : un stock sur un type QUI le déclare passe.
     *
     * ⚠ Sans lui, les deux tests précédents seraient également verts si le refus s'appliquait à
     * TOUS les stocks — et plus aucun produit boutique ne pourrait en recevoir un.
     */
    public function testUnStockSurUnTypeQuiLeDeclarePasse(): void
    {
        [$client, $token, $idA] = $this->adminSurA();

        // On bascule un produit vers un type portant la facette stock, en base, puis on écrit par
        // l'API : c'est le chemin que l'exploitant d'une boutique emprunte tous les jours.
        /** @var Produit $produit */
        $produit = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);
        $typeStock = $this->entite(\App\Offre\Entity\TypeProduit::class, ['code' => OffreFixtures::TYPE_CARTE]);
        $typeStock->setFacettes(['stock', 'consommateur']);
        $produit->setType($typeStock);
        $this->em()->flush();

        $id = (string) $produit->getId();
        $this->em()->clear();

        $client->request('PATCH', '/api/produits/'.$id, $this->patch($token, $idA) + [
            'json' => ['stock' => ['type' => 'dedie', 'disponibilite' => 12]],
        ]);
        self::assertResponseIsSuccessful('Un type déclarant la facette stock doit accepter un stock.');

        $this->em()->clear();
        /** @var Produit $apres */
        $apres = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);
        self::assertNotNull($apres->getStock());
        self::assertSame(12, $apres->getStock()->getDisponibilite());
    }
}
