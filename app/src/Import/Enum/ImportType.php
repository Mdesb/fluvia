<?php

declare(strict_types=1);

namespace App\Import\Enum;

/**
 * Ce qu'un lot de reprise contient (SPEC-REPRISE-INITIALE §4).
 *
 * **L'ordre est celui des dépendances, pas un ordre alphabétique.** `Customers` est la racine : tout
 * s'y rattache. Un lot d'abonnés déposé avant ses clients n'a rien à quoi se relier — c'est pour ça
 * que la reprise se fait type par type, dans cet ordre, et pas en un seul fichier.
 */
enum ImportType: string
{
    /** Personnes et organismes. La racine. */
    case Customers = 'customers';

    /** Catalogue, avec ses catégories comptables. */
    case Products = 'products';

    /** Grilles tarifaires. Un produit publié sans prix est refusé (D91). */
    case Tariffs = 'tariffs';

    /** Abonnements en cours : échéance, formule, mode de règlement. */
    case Subscribers = 'subscribers';

    /**
     * Crédits restants sur les cartes.
     *
     * ⚠ **Le plus sensible des six.** Un crédit restant est une
     * **dette envers le client** : il a payé dix entrées, en a consommé quatre, on lui en doit six.
     * Une erreur ne se voit pas à la reprise — elle se voit au guichet, six semaines plus tard,
     * devant la personne. Ce type exige en plus un rapprochement avec un total annoncé par le
     * client, et refuse le lot au moindre écart : on ne devine pas une dette.
     */
    case CardCredits = 'card_credits';

    /** Personnel et qualifications. */
    case Staff = 'staff';

    /**
     * Les types qu'un lot peut réellement porter aujourd'hui.
     *
     * Déclarer les six dès maintenant et n'en accepter qu'un est délibéré : l'énumération dit la
     * cible, cette méthode dit l'état. Un type absent d'ici est refusé à l'analyse avec un message
     * qui le nomme, plutôt que d'échouer plus loin sur une cause obscure.
     *
     * @return list<self>
     */
    public static function implemented(): array
    {
        return [self::Customers, self::CardCredits];
    }

    public function isImplemented(): bool
    {
        return \in_array($this, self::implemented(), true);
    }
}
