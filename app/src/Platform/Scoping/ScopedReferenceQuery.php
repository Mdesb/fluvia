<?php

declare(strict_types=1);

namespace App\Platform\Scoping;

use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Le filtre de lecture d'un référentiel « socle + ajout local » — **écrit une fois** (D51).
 *
 * **Pourquoi ce n'est pas laissé à chaque extension.** Le filtre juste tient en une expression, et le
 * filtre faux aussi. `etablissement = :courant` se lit comme une évidence, se relit comme une
 * évidence, et **fait disparaître tout le socle** : plus un tarif de base, plus un prix, catalogue
 * vide au guichet. Un module qui le réécrit a une chance sur deux de le réécrire ainsi.
 *
 * D51 : *en inventer un second serait pire que le problème.* C'est vrai des patrons de données comme
 * des filtres qui les lisent.
 *
 * **La lecture porte sur l'établissement ACTIF, et non sur toutes les affectations de l'utilisateur —
 * c'est la différence avec `PerimetreSupportExtension`, et elle est délibérée.** Un article d'aide se
 * lit légitimement depuis n'importe lequel de ses établissements ; **un référentiel tarifaire, non.**
 * Un responsable affecté à A et à B qui vend au guichet de A ne doit pas voir les types de tarif de B :
 * il pourrait poser un prix sur un tarif qui n'existe pas là où il encaisse, et le défaut ne se verrait
 * qu'à la facture.
 *
 * Le test l'a montré avant moi. Ma première version reprenait la règle des affectations de `Support`,
 * et l'administrateur — affecté à A **et** à B — voyait les ajouts de B depuis le guichet de A.
 *
 * `IDENTITY()` avec le type `'uuid'` explicite : sur une relation à identifiant `Uuid`, la comparaison
 * directe ne compte rien et **ne lève pas** (D58).
 */
final class ScopedReferenceQuery
{
    /** Restreint la requête au socle **plus** les ajouts de l'établissement actif. */
    public static function restreindre(QueryBuilder $queryBuilder, ?Uuid $etablissementActif): void
    {
        $alias = $queryBuilder->getRootAliases()[0];

        if ($etablissementActif === null) {
            // Fermeture par défaut : sans établissement actif, on ne montre que le socle. Montrer
            // « tout » serait la seule erreur irrattrapable de ce fichier.
            $queryBuilder->andWhere(sprintf("%s.portee = '%s'", $alias, ReferenceScope::Base->value));

            return;
        }

        $parametre = 'portee_etablissement_' . substr(md5($alias), 0, 8);

        $queryBuilder
            ->andWhere(sprintf(
                "(%s.portee = '%s' OR IDENTITY(%s.etablissement) = :%s)",
                $alias,
                ReferenceScope::Base->value,
                $alias,
                $parametre,
            ))
            ->setParameter($parametre, $etablissementActif, 'uuid');
    }
}
