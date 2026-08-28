<?php

declare(strict_types=1);

namespace App\Offre\Doctrine;

use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * LA VISIBILITÉ D'UN PRODUIT — écrite une fois, appliquée partout.
 *
 * Un produit est visible s'il est **du socle** — aucun établissement, donc partagé par tous — ou
 * s'il appartient à l'établissement actif. C'est le patron D51 dans sa variante « socle + ajout
 * local », et c'est exactement ce que `PerimetreProduitExtension` appliquait déjà aux collections.
 *
 * Elle vit ici depuis le 28/08 parce qu'un second appelant est apparu : les photos de produit se
 * résolvent par `find()`, hors d'API Platform, où aucune extension ne passe. Recopier la clause
 * aurait donné deux versions d'une même règle, et la seconde aurait cessé de suivre la première.
 *
 * > **Un seul calcul, plusieurs appelants.**
 *
 * ── LA JOINTURE EST EXTERNE, ET C'EST TOUT L'ENJEU ──────────────────────────────────────────────
 *
 * En interne, un produit sans établissement ne satisfait aucune ligne : il serait invisible de tous.
 * Or « sans établissement » est précisément la façon dont ce dépôt écrit « socle » pour cette
 * entité — elle n'a ni discriminant `portee`, ni colonne `etablissement`.
 *
 * ── SANS ÉTABLISSEMENT ACTIF : LE SOCLE, ET RIEN D'AUTRE ────────────────────────────────────────
 *
 * Fermeture par défaut. Montrer « tout » serait la seule erreur irrattrapable de ce fichier.
 */
final class ProductScope
{
    /**
     * Restreint `$alias` — qui DOIT désigner un `Produit` — au socle plus l'établissement actif.
     *
     * @param string $alias l'alias du produit dans la requête, pas celui de l'entité appelante
     */
    public static function restreindre(QueryBuilder $qb, string $alias, ?Uuid $actif): void
    {
        $qb->leftJoin($alias . '.etablissements', 'portee_etab');

        if ($actif === null) {
            $qb->andWhere(sprintf('SIZE(%s.etablissements) = 0', $alias))->distinct();

            return;
        }

        // ⚠ `portee_etab.id = :param` typé `'uuid'` explicitement : sur un identifiant à type
        // personnalisé, une comparaison sans type ne compte rien **et ne lève pas** (D58,
        // garde-fou n°16). Ici elle ne rendrait pas « moins » — elle rendrait *le socle seul*, ce
        // qui ressemble à une configuration incomplète bien plus qu'à un défaut.
        $qb
            ->andWhere(sprintf('(portee_etab.id = :portee_actif OR SIZE(%s.etablissements) = 0)', $alias))
            ->setParameter('portee_actif', $actif, 'uuid')
            ->distinct();
    }
}
