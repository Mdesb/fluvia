<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Securite\Service\ContexteEtablissement;
use App\Offre\Entity\Stock;
use App\Tests\Offre\OffreApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Une écriture acceptée qui n'enregistre rien : le stock posé sur un type qui n'a pas la facette.
 *
 * ── CE QUE CE TEST DÉCRIT, ET POURQUOI IL EST VERT ─────────────────────────────────────────────
 *
 * `ResolveurFacettes::purgerOrphelins()` est appelé par `ProduitProcessor` à **chaque**
 * enregistrement : si le type du produit ne déclare pas la facette `stock`, le stock est détaché.
 * C'est la règle RG-M1-02 / CA-3, et elle est délibérée.
 *
 * ⚠ MAIS L'API RÉPOND 200 ET RENVOIE `stock: null` DANS LA MÊME RÉPONSE. L'appelant a écrit une
 * disponibilité de 40, le serveur a accepté, et rien n'est enregistré. Aucun 422, aucun message,
 * aucune trace. C'est le quatrième patron des « 200 menteurs » : l'écriture acceptée sans effet.
 *
 * ⚠ ET AUCUN ÉCRAN NE PRÉVIENT. Mesuré le 30/08 : `facettes` n'apparaît nulle part dans
 * `frontend/src` — les sections de `ProduitFiche.jsx` sont conditionnées par la vue, les droits et
 * la présence de données, jamais par le type. La docstring de `Produit` affirme pourtant que « le
 * type pilote les onglets/facettes visibles ». Elle décrit une intention, pas le code servi.
 *
 * ── LE SECOND CAS EST LE PLUS COÛTEUX, ET C'EST CELUI QUI A DES VICTIMES EN PRÉPRODUCTION ──────
 *
 * Un stock déjà posé (par fixture, par import, par une conversion de type) disparaît au prochain
 * enregistrement d'un champ **sans rapport** — le libellé, la couleur de caisse. L'exploitant
 * modifie un nom et perd une jauge ; la cause et l'effet n'ont rien à voir à l'écran.
 *
 * Relevé en préprod le 30/08 : deux produits de type `entree_unitaire` (facettes
 * `["billet","consommateur"]`, donc sans `stock`) portent un stock —
 * `PRD-PLACE01 « Place limitée (stock 1) »` et `PRD-CADENAS01 « Cadenas vestiaire (rupture) »`.
 * Les deux le perdront à la première modification.
 *
 * ── CE TEST NE JUGE PAS LA RÈGLE, IL LA REND VISIBLE ───────────────────────────────────────────
 *
 * Il passe aujourd'hui et décrit le comportement réel. Le jour où quelqu'un décide qu'une écriture
 * refusée vaut mieux qu'une écriture avalée — un 422 « ce type de produit ne gère pas de stock » —
 * ce test **échouera**, et c'est exactement ce qu'on attend de lui : il tiendra la décision, au lieu
 * de laisser le changement passer inaperçu.
 *
 * La question de fond est pour Maxime, et elle appartient au débrief des typologies : il a défini
 * « produit simple » comme **sans stock**, ce qui donne raison aux facettes. Mais « Place limitée »
 * est un besoin réel — une entrée avec une jauge n'est pas de la marchandise. Voir
 * `COORDINATION/specs/offre/SPEC-TYPOLOGIES-PRODUITS.md`.
 */
final class FacettePurgeSilencieuseTest extends OffreApiTestCase
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
     * API Platform le refuse en 415. Constaté ici même au premier lancement. D'où cette fonction :
     * un seul endroit qui compose l'en-tête, et personne n'a à s'en souvenir.
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

    /** Le stock écrit sur un type sans la facette : accepté, puis absent de la même réponse. */
    public function testStockEcritSurUnTypeSansFacetteEstAvaleSansErreur(): void
    {
        [$client, $token, $idA] = $this->adminSurA();

        $idEntree = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);

        // `entree_unitaire` déclare ["billet","consommateur"] — pas `stock`.
        $reponse = $client->request('PATCH', '/api/produits/'.$idEntree, $this->patch($token, $idA) + [
            'json' => ['stock' => ['type' => 'dedie', 'disponibilite' => 40]],
        ])->toArray();

        // ⚠ L'écriture est acceptée. C'est là tout le défaut : pas de 422, pas de message.
        self::assertResponseIsSuccessful('Le serveur accepte un stock sur un type qui ne le gère pas.');

        // ⚠ TÉMOIN DE NON-VACUITÉ AVANT L'ASSERTION D'ABSENCE. Une première version écrivait
        // `assertArrayNotHasKey('stock', array_filter(…))` : vraie aussi d'une réponse vide, donc
        // vraie pour une raison qui n'a rien à voir. Le garde-fou « vacuité des tests » l'a refusée
        // au commit, et il avait raison. On prouve d'abord que la réponse est bien celle du produit.
        self::assertSame(
            OffreFixtures::PRODUIT_ENTREE,
            $reponse['libelleRecherche'] ?? null,
            'La réponse doit être celle du produit : sans quoi son « pas de stock » ne prouve rien.',
        );

        // Et alors seulement : elle ne porte aucun stock.
        self::assertNull($reponse['stock'] ?? null, 'purgerOrphelins vient de le détacher.');

        // Et rien n'est en base non plus — la réponse pourrait mentir par sérialisation.
        $this->em()->clear();
        /** @var Produit $produit */
        $produit = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);
        self::assertNull($produit->getStock(), 'Le stock ne doit pas exister en base.');
    }

    /**
     * Le cas coûteux : un stock POSÉ disparaît en modifiant un champ sans rapport.
     *
     * ⚠ C'est le témoin qui distingue « l'écriture de stock est ignorée » de « toute donnée de stock
     * est détruite ». Sans lui, le premier test seul laisserait croire que les stocks déjà en place
     * sont à l'abri — ils ne le sont pas, et deux produits de la préprod sont dans ce cas.
     */
    public function testUnStockDejaPoseDisparaitEnModifiantLeLibelle(): void
    {
        [$client, $token, $idA] = $this->adminSurA();

        // On pose le stock par l'ORM, comme l'ont fait la fixture et l'import qui ont produit
        // PRD-PLACE01 : la voie API est justement celle que le premier test montre inopérante.
        /** @var Produit $produit */
        $produit = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);
        $stock = (new Stock())->setType('dedie')->setDisponibilite(7);
        $produit->setStock($stock);
        $this->em()->persist($stock);
        $this->em()->flush();

        $idEntree = (string) $produit->getId();
        $this->em()->clear();

        // Témoin positif : le stock est bien là AVANT. Sans cette assertion, un test vert ne
        // distinguerait pas « le stock a été purgé » de « le stock n'a jamais été posé ».
        /** @var Produit $avant */
        $avant = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);
        self::assertNotNull($avant->getStock(), 'Le stock doit exister avant la modification.');
        self::assertSame(7, $avant->getStock()->getDisponibilite());

        // Une modification qui ne parle pas de stock du tout.
        $client->request('PATCH', '/api/produits/'.$idEntree, $this->patch($token, $idA) + [
            'json' => ['couleurCaisse' => '#123456'],
        ]);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        /** @var Produit $apres */
        $apres = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);

        self::assertSame('#123456', $apres->getCouleurCaisse(), 'La couleur demandée est enregistrée.');
        self::assertNull(
            $apres->getStock(),
            "⚠ Le stock a disparu en changeant une couleur. Si cette assertion échoue, c'est que "
            ."quelqu'un a corrigé le défaut — supprimez ce test et gardez le refus explicite.",
        );
    }
}
