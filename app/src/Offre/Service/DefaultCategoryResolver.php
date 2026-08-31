<?php

declare(strict_types=1);

namespace App\Offre\Service;

use App\Offre\Entity\Categorie;
use App\Offre\Entity\Produit;
use App\Offre\Enum\AxeCategorie;
use App\Platform\Scoping\ReferenceScope;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LES CATÉGORIES QU'UN PRODUIT DEVRAIT PORTER, SANS QU'ON LES SAISISSE.
 *
 * **La demande, telle qu'elle est écrite dans `TASKS.md` (ACT-5) :** *« un produit d'affûtage doit
 * tomber dans les bons axes sans saisie »*. Elle a l'air d'un confort ; elle ne l'est pas. L'axe
 * comptable est **obligatoire pour publier** (RG-M1-05) : un produit créé sans lui reste bloqué en
 * brouillon, et l'exploitant qui ne connaît pas la règle cherche pourquoi son produit ne se vend pas.
 *
 * > **Une saisie obligatoire que l'utilisateur ne sait pas faire n'est pas une contrainte : c'est un
 * > blocage sans message.**
 *
 * ---
 *
 * **OÙ SONT ÉCRITS CES DÉFAUTS, ET POURQUOI PAS AILLEURS.**
 *
 * Dans `TypeProduit::$defauts` — un champ déclaré depuis le début et **que personne ne lisait**
 * (vérifié le 27/08 : aucune occurrence de `getDefauts()` hors de l'entité). Son commentaire annonçait
 * déjà *« valeurs par défaut héritées (marges compostage, TVA, durée…) »* : les catégories y sont chez
 * elles.
 *
 * Forme attendue, sous la clé `categories` :
 *
 * ```
 * { "categories": { "comptable": "Billetterie", "marketing": "Glace" } }
 * ```
 *
 * **Les catégories sont désignées par leur LIBELLÉ, pas par leur identifiant**, et c'est structurant.
 * `Categorie` est un référentiel « socle + ajout local » (D51) : chaque établissement voit le socle
 * **plus** ses propres ajouts. Un identifiant figé dans un défaut de type désignerait la catégorie
 * d'un établissement précis — et rendrait le type inutilisable partout ailleurs, sans erreur, en
 * n'appliquant simplement rien.
 *
 * ---
 *
 * **CE SERVICE NE CRÉE JAMAIS DE CATÉGORIE.**
 *
 * Si le libellé n'existe pas pour cet établissement, il n'applique rien. Fabriquer la catégorie
 * manquante paraîtrait serviable et polluerait le référentiel comptable de chaque exploitant avec des
 * libellés qu'il n'a pas décidés — dans un plan de comptes, c'est le genre de chose qui se découvre à
 * l'export FEC.
 *
 * **Et il n'écrase jamais un axe déjà renseigné.** Un défaut n'est pas une règle : si l'utilisateur a
 * choisi, il a raison.
 */
final class DefaultCategoryResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Applique les catégories par défaut du type, sur les axes encore vides.
     *
     * @return list<string> les axes qui n'ont pas pu être remplis, pour que l'appelant puisse le dire
     */
    public function appliquer(Produit $produit): array
    {
        $type = $produit->getType();
        $defauts = $type?->getDefauts()['categories'] ?? null;
        if (!\is_array($defauts) || $defauts === []) {
            return [];
        }

        $dejaPris = [];
        foreach ($produit->getCategories() as $categorie) {
            \assert($categorie instanceof Categorie);
            $axe = $categorie->getAxe();
            if ($axe !== null) {
                $dejaPris[$axe->value] = true;
            }
        }

        $manquants = [];
        foreach ($defauts as $axeValeur => $libelle) {
            if (!\is_string($axeValeur) || !\is_string($libelle) || trim($libelle) === '') {
                continue;
            }

            $axe = AxeCategorie::tryFrom($axeValeur);
            if ($axe === null) {
                // Un axe inconnu dans les défauts est une erreur de paramétrage, pas une raison
                // d'échouer la création d'un produit. On le signale, on continue.
                $manquants[] = $axeValeur;
                continue;
            }

            // RG-M1-05 : une valeur par axe. On ne touche pas un axe que l'utilisateur a renseigné —
            // un défaut n'est pas une règle.
            if (isset($dejaPris[$axe->value])) {
                continue;
            }

            $categorie = $this->trouver($axe, trim($libelle), $produit);
            if ($categorie === null) {
                $manquants[] = $axe->value;
                continue;
            }

            $produit->addCategorie($categorie);
            $dejaPris[$axe->value] = true;
        }

        return $manquants;
    }

    /**
     * La catégorie visible de ce produit : le socle, **plus** les ajouts de ses établissements.
     *
     * C'est la règle de lecture de D51, appliquée ici à la main parce qu'on interroge hors du contexte
     * d'une requête API — `ScopedReferenceQuery` lit l'établissement **actif** depuis l'en-tête, or un
     * produit peut être créé pour un établissement qui n'est pas celui de l'en-tête (import, script).
     * On part donc du produit, qui est la seule source sûre de son propre rattachement.
     */
    private function trouver(AxeCategorie $axe, string $libelle, Produit $produit): ?Categorie
    {
        $qb = $this->em->getRepository(Categorie::class)->createQueryBuilder('c')
            ->andWhere('c.axe = :axe')
            ->andWhere('c.libelle = :libelle')
            ->setParameter('axe', $axe->value)
            ->setParameter('libelle', $libelle)
            ->setMaxResults(1);

        $ids = [];
        foreach ($produit->getEtablissements() as $etablissement) {
            $ids[] = $etablissement->getId();
        }

        if ($ids === []) {
            // Produit du socle : seules les catégories du socle le concernent.
            $qb->andWhere(sprintf("c.portee = '%s'", ReferenceScope::Base->value));
        } else {
            // ⚠ `IN` sur une relation à identifiant `Uuid` ne trouve RIEN et ne lève pas (D58). On
            // compare donc un identifiant à la fois, avec son type explicite — la liste tient dans
            // les doigts d'une main, et une requête juste vaut mieux qu'une requête élégante.
            $ou = [sprintf("c.portee = '%s'", ReferenceScope::Base->value)];
            foreach ($ids as $rang => $id) {
                $ou[] = sprintf('IDENTITY(c.etablissement) = :etab%d', $rang);
                $qb->setParameter('etab' . $rang, $id, 'uuid');
            }
            $qb->andWhere('(' . implode(' OR ', $ou) . ')');
        }

        $categorie = $qb->getQuery()->getOneOrNullResult();

        return $categorie instanceof Categorie ? $categorie : null;
    }
}
