<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Enum\StatutProduit;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * On ne peut pas retirer à un produit PUBLIÉ son dernier prix — et on peut tout le reste.
 *
 * ── LE TROU QUE CE FICHIER FERME ────────────────────────────────────────────────────────────────
 *
 * `PublicationGuard` exige ≥1 prix valide pour publier, et il n'était rejoué NULLE PART ensuite. Un
 * PATCH sur une case de grille pouvait donc rendre invendable un produit qui restait « Publié » :
 * pas d'erreur, pas de changement de statut, rien. C'est `allaccess-34` qui l'a relevé, et Maxime a
 * tranché le 30/08 — la règle vaut aux deux portes, pas seulement à la première.
 *
 * ── LES QUATRE CAS, ET POURQUOI AUCUN N'EST DE TROP ─────────────────────────────────────────────
 *
 * Deux disent ce qui est refusé, deux disent ce qui reste permis. **C'est la seconde paire qui
 * distingue une garde d'un blocage** : sans elle, un processor qui refuserait tout PATCH de grille
 * passerait les deux premiers tests et rendrait les tarifs inmodifiables.
 *
 * 1. vider le SEUL prix d'un publié               → 422, message nommant le produit
 * 2. DÉPLACER la seule case d'un publié ailleurs  → 422, l'autre chemin vers le même état
 * 3. vider UN prix parmi plusieurs                → permis, et le voisin survit
 * 4. vider le seul prix d'un BROUILLON            → permis, un brouillon n'est pas en vente
 *
 * Le cas 2 est celui qu'on n'écrit pas spontanément : on pense au prix qu'on efface, pas à la case
 * qu'on déménage. Il interdit aussi de se fier à `Produit::aPrixValide()`, dont les collections en
 * mémoire sont fausses des deux côtés au moment du PATCH.
 */
final class DernierPrixDUnProduitPublieTest extends OffreApiTestCase
{
    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /**
     * ⚠ `$a + $b` GARDE LA GAUCHE : composer l'en-tête ailleurs jetterait le `Content-Type` et le
     * PATCH partirait en `ld+json`, refusé en 415 par un message qui parle de types MIME.
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

    /**
     * Les fixtures ne publient rien — un produit publiable n'est pas un produit publié. On pose donc
     * le statut par l'ORM : ce qu'on éprouve ici est la SECONDE porte, pas la première.
     */
    private function publier(string $libelleRecherche): Produit
    {
        /** @var Produit $produit */
        $produit = $this->entite(Produit::class, ['libelleRecherche' => $libelleRecherche]);
        $produit->setStatut(StatutProduit::Publie);
        $this->em()->flush();

        return $produit;
    }

    /** @return list<GrilleTarifaire> */
    private function grilles(Produit $produit): array
    {
        return $this->em()->getRepository(GrilleTarifaire::class)->findBy(['produit' => $produit]);
    }

    /** 1. Vider le seul prix d'un produit publié : refusé, et le message nomme le produit. */
    public function testViderLeSeulPrixDUnProduitPublieEstRefuse(): void
    {
        [$client, $token, $idA] = $this->adminSurA();

        $gold = $this->publier(OffreFixtures::PRODUIT_GOLD);
        $grilles = $this->grilles($gold);

        // Témoin positif : sans lui, un test vert ne distinguerait pas « le prix a résisté » de
        // « le produit n'avait qu'une case vide dès le départ ».
        self::assertCount(1, $grilles, 'Le cas ne vaut que si ce prix est bien le seul.');
        self::assertSame('39.90', $grilles[0]->getPrix());

        $id = (string) $grilles[0]->getId();
        $this->em()->clear();

        $client->request('PATCH', '/api/grille_tarifaires/'.$id, $this->patch($token, $idA) + [
            'json' => ['prix' => null],
        ]);

        self::assertResponseStatusCodeSame(422);

        $corps = $client->getResponse()->getContent(false);
        self::assertStringContainsString('PRD-GOLD01', $corps, 'Le message doit nommer le produit concerné.');
        self::assertStringContainsString('Dépublier', $corps, 'Le message doit dire par où sortir.');

        // Et rien n'a été écrit.
        $this->em()->clear();
        /** @var Produit $apres */
        $apres = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_GOLD]);
        self::assertSame('39.90', $this->grilles($apres)[0]->getPrix());
    }

    /**
     * 2. Déplacer la seule case d'un produit publié vers un autre produit : refusé aussi.
     *
     * ⚠ La destination est un produit NEUF sans aucune grille. Viser une fixture existante ferait
     * buter le PATCH sur `uniq_grille_triplet` — et un 500 de contrainte ressemble assez à un refus
     * pour qu'on croie la garde efficace alors qu'elle n'aurait jamais été atteinte.
     */
    public function testDeplacerLaSeuleCaseDUnProduitPublieEstRefuse(): void
    {
        [$client, $token, $idA] = $this->adminSurA();

        $gold = $this->publier(OffreFixtures::PRODUIT_GOLD);
        $grille = $this->grilles($gold)[0];
        $idGrille = (string) $grille->getId();

        $accueil = (new Produit())
            ->setType($gold->getType())
            ->setLibelle(['fr' => 'Produit sans tarif'])
            ->setLibelleRecherche('Produit sans tarif')
            ->setCode('PRD-VIDE01')
            ->setCanaux(['guichet'])
            ->setStatut(StatutProduit::Brouillon);
        foreach ($gold->getEtablissements() as $etablissement) {
            $accueil->addEtablissement($etablissement);
        }
        $this->em()->persist($accueil);
        $this->em()->flush();

        $idAccueil = (string) $accueil->getId();
        $this->em()->clear();

        $client->request('PATCH', '/api/grille_tarifaires/'.$idGrille, $this->patch($token, $idA) + [
            'json' => ['produit' => '/api/produits/'.$idAccueil],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'PRD-GOLD01',
            $client->getResponse()->getContent(false),
            'Le message doit nommer le produit DÉPOUILLÉ, pas celui qui reçoit.'
        );

        // La case n'a pas bougé.
        $this->em()->clear();
        /** @var Produit $apres */
        $apres = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_GOLD]);
        self::assertCount(1, $this->grilles($apres), 'La case doit être restée sur son produit.');
    }

    /**
     * 3. Vider UN prix parmi plusieurs : permis.
     *
     * Un prix null veut dire « non commercialisé » (≠ gratuit, CA-5) — retirer un tarif de la vente
     * est un geste métier, pas une faute, tant qu'il reste de quoi vendre.
     */
    public function testViderUnPrixParmiPlusieursEstPermis(): void
    {
        [$client, $token, $idA] = $this->adminSurA();

        $entree = $this->publier(OffreFixtures::PRODUIT_ENTREE);
        $grilles = $this->grilles($entree);
        self::assertCount(2, $grilles, 'Le cas ne vaut que si le produit porte bien deux prix.');

        $cible = (string) $grilles[0]->getId();
        $voisin = (string) $grilles[1]->getId();
        $prixVoisin = $grilles[1]->getPrix();
        $this->em()->clear();

        $client->request('PATCH', '/api/grille_tarifaires/'.$cible, $this->patch($token, $idA) + [
            'json' => ['prix' => null],
        ]);

        self::assertResponseIsSuccessful('Un tarif parmi plusieurs doit rester retirable de la vente.');

        $this->em()->clear();
        /** @var GrilleTarifaire $videe */
        $videe = $this->em()->getRepository(GrilleTarifaire::class)->find($cible);
        self::assertNull($videe->getPrix(), "Le prix visé doit bien avoir été vidé.");

        /** @var GrilleTarifaire $survivant */
        $survivant = $this->em()->getRepository(GrilleTarifaire::class)->find($voisin);
        self::assertSame($prixVoisin, $survivant->getPrix(), "Le prix voisin ne doit pas avoir bougé.");
    }

    /** 4. Vider le seul prix d'un BROUILLON : permis — un brouillon n'est pas en vente. */
    public function testViderLeSeulPrixDUnBrouillonEstPermis(): void
    {
        [$client, $token, $idA] = $this->adminSurA();

        /** @var Produit $carte */
        $carte = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_CARTE]);
        self::assertSame(StatutProduit::Brouillon, $carte->getStatut(), 'Le cas suppose un brouillon.');

        $grilles = $this->grilles($carte);
        self::assertCount(1, $grilles, 'Le cas ne vaut que si ce prix est le seul.');

        $id = (string) $grilles[0]->getId();
        $this->em()->clear();

        $client->request('PATCH', '/api/grille_tarifaires/'.$id, $this->patch($token, $idA) + [
            'json' => ['prix' => null],
        ]);

        self::assertResponseIsSuccessful('Un brouillon doit rester librement modifiable.');

        $this->em()->clear();
        /** @var GrilleTarifaire $videe */
        $videe = $this->em()->getRepository(GrilleTarifaire::class)->find($id);
        self::assertNull($videe->getPrix());
    }
}
