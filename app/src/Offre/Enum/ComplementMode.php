<?php

declare(strict_types=1);

namespace App\Offre\Enum;

/**
 * COMMENT UN COMPLÉMENT SE PRÉSENTE À LA CAISSE — ET CE QU'IL EMPÊCHE.
 *
 * ── POURQUOI TROIS VALEURS ET NON UN BOOLÉEN ────────────────────────────────────────────────────
 *
 * La question posée était : un complément obligatoire **bloque-t-il la vente**, ou est-il seulement
 * proposé coché ? Les deux existent dans la vraie vie — le bonnet de bain exigé par le règlement
 * intérieur n'est pas la serviette qu'on propose par défaut — et un booléen `obligatoire` qui se
 * décoche serait un nom qui ment.
 *
 * ⚠ ET LES DEUX NE SE CORRIGENT PAS DE LA MÊME FAÇON QUAND ON S'EST TROMPÉ. Un `Suggested` posé à
 * tort se décoche ; un `Required` posé à tort **arrête une file d'attente**, et l'agent n'a aucun
 * moyen de passer outre. C'est pourquoi `Required` doit être choisi, jamais obtenu par défaut.
 */
enum ComplementMode: string
{
    /** Proposé décoché. L'agent l'ajoute s'il le veut. */
    case Optional = 'optional';

    /**
     * Proposé coché, et retirable.
     *
     * C'est le cas le plus fréquent : on veut que l'agent y pense sans lui imposer. Le retirer est
     * un geste, donc il est conscient — ce qui suffit dans presque tous les cas.
     */
    case Suggested = 'suggested';

    /**
     * La vente ne se valide pas sans lui.
     *
     * ⚠ Réservé à ce qu'une règle EXTÉRIEURE impose — un règlement intérieur, une obligation
     * légale — jamais à une préférence commerciale. Le jour où le complément manque en stock, la
     * vente devient impossible : c'est voulu quand c'est le bonnet obligatoire, et c'est une panne
     * quand c'est une serviette.
     */
    case Required = 'required';
}
