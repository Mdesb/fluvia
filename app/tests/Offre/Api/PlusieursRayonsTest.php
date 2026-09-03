<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\Offre\Entity\Categorie;
use App\Offre\Enum\AxeCategorie;
use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;

/**
 * UN PRODUIT PEUT TENIR DANS PLUSIEURS RAYONS — R3.
 *
 * Maxime : « il doit avoir la possibilité de créer différents rayons, et les produits peuvent être
 * assignés à plusieurs rayons ». Un rayon de caisse est un rangement d'AFFICHAGE : une canette est
 * légitimement dans « Boissons » et dans « Snacks ».
 *
 * ⚠ CE N'EST PAS LE MODÈLE QUI L'INTERDISAIT, C'ÉTAIT L'ÉCRAN. `Produit::$categories` est un
 * ManyToMany, et la règle « une valeur par axe » (RG-M1-05) n'est écrite QUE dans un docblock —
 * aucun validateur, aucun processeur ne l'applique. La fiche produit, elle, n'offrait qu'un
 * `<select>` par axe : un `find`, au singulier.
 *
 * ⚠ CE TEST EXISTE PARCE QUE J'AI AFFIRMÉ « AUCUN VALIDATEUR NE L'IMPOSE » APRÈS UNE RECHERCHE, ET
 * QU'UNE RECHERCHE QUI NE TROUVE RIEN N'EST PAS UNE PREUVE. Si un processeur écartait les
 * catégories surnuméraires en silence, l'écran laisserait cocher des cases qui ne s'enregistrent
 * pas — et personne ne verrait la différence avant de chercher un produit dans un rayon vide.
 */
final class PlusieursRayonsTest extends OffreApiTestCase
{
    public function testUnProduitAcceptePlusieursRayonsEtLesRendTOUS(): void
    {
        [$http, $jeton, $idA] = $this->adminSurA();
        [$boissons, $snacks] = $this->deuxRayons();
        $produit = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);

        $reponsePatch = $http->request('PATCH', '/api/produits/' . $produit, [
            'auth_bearer' => $jeton,
            'headers' => [ContexteEtablissement::HEADER => $idA, 'Content-Type' => 'application/merge-patch+json'],
            'json' => ['categories' => ['/api/categories/' . $boissons, '/api/categories/' . $snacks]],
        ])->toArray();
        self::assertResponseIsSuccessful();
        $rendus = $this->rayonsDe($http, $jeton, $idA, $produit);

        // ⚠ `assertContains` ET NON UNE EGALITE : `DefaultCategoryResolver` ajoute les defauts du
        // TYPE sur les axes encore vides — comptable et marketing. Exiger exactement deux
        // categories ferait echouer ce test sur un comportement voulu, et pousserait le prochain a
        // « corriger » le resolveur.
        self::assertContains($boissons, $rendus, 'le premier rayon doit etre conserve');
        self::assertContains(
            $snacks,
            $rendus,
            'LE SECOND AUSSI — s’il disparaît, l’écran laisse cocher des cases qui ne s’enregistrent pas',
        );
    }

    /**
     * ⚠ ET LE TÉMOIN QUI PROUVE QUE L'ÉCRITURE MORD VRAIMENT.
     *
     * Sans lui, un `PATCH` silencieusement ignoré laisserait le produit avec ses catégories
     * d'origine — et si l'une d'elles était par chance l'un des deux rayons, le test ci-dessus
     * passerait sans que rien n'ait été écrit. On vérifie donc qu'on peut aussi RETIRER.
     */
    public function testOnPeutAussiRetirerUnRayon(): void
    {
        [$http, $jeton, $idA] = $this->adminSurA();
        [$boissons, $snacks] = $this->deuxRayons();
        $produit = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);

        $poser = function (array $ids) use ($http, $jeton, $idA, $produit): array {
            $http->request('PATCH', '/api/produits/' . $produit, [
                'auth_bearer' => $jeton,
                'headers' => [ContexteEtablissement::HEADER => $idA, 'Content-Type' => 'application/merge-patch+json'],
                'json' => ['categories' => array_map(static fn (string $i): string => '/api/categories/' . $i, $ids)],
            ]);
            self::assertResponseIsSuccessful();

            return $this->rayonsDe($http, $jeton, $idA, $produit);
        };

        $deux = $poser([$boissons, $snacks]);
        self::assertContains($snacks, $deux, 'témoin : les deux sont bien posés');

        $un = $poser([$boissons]);
        self::assertContains($boissons, $un);
        self::assertNotContains($snacks, $un, 'retirer un rayon doit le retirer pour de bon');
    }

    /**
     * ⚠ LE CONTRAT DONT LA CAISSE DÉPEND POUR RANGER SES TUILES.
     *
     * Elle groupe par rayon en lisant, sur chaque produit de la COLLECTION, ses catégories
     * embarquées avec leur `axe` et leur `libelle`. Si la collection ne rendait que des IRI nues —
     * ou si `axe` quittait le groupe `produit:read` — la barre des rayons deviendrait vide **en
     * silence** : aucune erreur, aucune tuile manquante, juste un rangement qui cesse d'exister.
     *
     * Rien ne gardait ce contrat entre deux modules.
     */
    public function testLaCollectionEmbarqueLAxeEtLeLibelleDesCategories(): void
    {
        [$http, $jeton, $idA] = $this->adminSurA();
        [$boissons] = $this->deuxRayons();
        $produit = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);

        $http->request('PATCH', '/api/produits/' . $produit, [
            'auth_bearer' => $jeton,
            'headers' => [ContexteEtablissement::HEADER => $idA, 'Content-Type' => 'application/merge-patch+json'],
            'json' => ['categories' => ['/api/categories/' . $boissons]],
        ]);
        self::assertResponseIsSuccessful();

        $collection = $http->request('GET', '/api/produits?itemsPerPage=200', [
            'auth_bearer' => $jeton,
            'headers' => [ContexteEtablissement::HEADER => $idA],
        ])->toArray();

        $lignes = $collection['member'] ?? $collection['hydra:member'] ?? [];
        $laLigne = null;
        foreach ($lignes as $ligne) {
            if (basename((string) ($ligne['@id'] ?? '')) === $produit) {
                $laLigne = $ligne;
                break;
            }
        }
        self::assertNotNull($laLigne, 'témoin : le produit est bien dans la collection');

        $rayons = array_values(array_filter(
            $laLigne['categories'] ?? [],
            static fn (mixed $cat): bool => \is_array($cat) && ($cat['axe'] ?? null) === 'rayon',
        ));

        self::assertCount(
            1,
            $rayons,
            'la collection doit embarquer les catégories en OBJETS avec leur axe — des IRI nues '
            . 'videraient la barre des rayons de la caisse sans le moindre signe',
        );
        self::assertSame('Boissons', $rayons[0]['libelle'] ?? null, 'et leur libellé, qui est le texte du bouton');
    }

    /**
     * Les identifiants des catégories que le produit porte, telles que l'API les rend.
     *
     * Une relation arrive tantôt en objet (`{ '@id': … }`), tantôt en IRI nue, selon les groupes
     * de sérialisation. On compare des identifiants, jamais des formes.
     *
     * @return list<string>
     */
    private function rayonsDe(object $http, string $jeton, string $idA, string $produit): array
    {
        $relu = $http->request('GET', '/api/produits/' . $produit, [
            'auth_bearer' => $jeton,
            'headers' => [ContexteEtablissement::HEADER => $idA],
        ])->toArray();

        return array_map(
            static fn (mixed $c): string => basename(\is_array($c) ? ($c['@id'] ?? '') : (string) $c),
            $relu['categories'] ?? [],
        );
    }

    /**
     * Deux rayons, créés pour ce test.
     *
     * ⚠ La préproduction n'en porte AUCUN — 9 catégories comptables, 1 marketing, zéro rayon.
     * L'axe existe depuis toujours et l'écran de paramétrage sait le créer ; personne ne s'en
     * était servi. C'est ce qui a failli me faire construire un écran qui existe déjà.
     *
     * @return array{0: string, 1: string}
     */
    private function deuxRayons(): array
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $ids = [];
        foreach (['Boissons', 'Snacks'] as $libelle) {
            $categorie = (new Categorie())->setLibelle($libelle)->setAxe(AxeCategorie::Rayon);
            $em->persist($categorie);
            $ids[] = $categorie;
        }
        $em->flush();

        return [(string) $ids[0]->getId(), (string) $ids[1]->getId()];
    }
}
