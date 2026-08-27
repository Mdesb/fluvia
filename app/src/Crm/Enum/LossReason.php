<?php

declare(strict_types=1);

namespace App\Crm\Enum;

/**
 * POURQUOI UNE AFFAIRE A ÉTÉ PERDUE — **la seule donnée du pipeline qui serve encore dans six mois.**
 *
 * Le reste du tableau est périssable : les montants prévisionnels sont oubliés le trimestre suivant,
 * les étapes n'intéressent personne une fois l'affaire close. Les motifs de perte, eux, s'additionnent
 * — et au bout de trente affaires ils disent quelque chose qu'aucun autre écran ne dit : *trop cher*,
 * *trop tard*, ou *on ne rappelle pas*.
 *
 * **Une liste fermée, et c'est le point.** Un champ libre produirait trente formulations de la même
 * chose, donc rien de dénombrable — c'est-à-dire exactement ce qu'on cherchait à éviter. Le
 * commentaire libre existe **à côté** : il porte le détail, la liste porte le décompte.
 *
 * > **Ce qu'on ne peut pas compter ne se corrige pas.**
 */
enum LossReason: string
{
    /** Le prix. Le plus fréquent, et celui qu'on croit connaître sans le mesurer. */
    case Price = 'price';

    /** Le délai : la date demandée n'était pas tenable, ou la réponse est arrivée trop tard. */
    case Deadline = 'deadline';

    /** Un concurrent. */
    case Competitor = 'competitor';

    /**
     * Sans suite : le client n'a plus donné signe.
     *
     * ⚠ **Celui-ci mérite d'être distingué des autres, et pas seulement pour la statistique.** Une
     * affaire sans suite n'est pas une affaire perdue sur le fond : c'est souvent une relance qui n'a
     * pas été faite. Si ce motif domine, le problème n'est pas commercial, il est organisationnel.
     */
    case NoFollowUp = 'no_follow_up';

    /** Le projet lui-même a été abandonné, indépendamment de nous. */
    case Cancelled = 'cancelled';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Price => 'Prix',
            self::Deadline => 'Délai',
            self::Competitor => 'Concurrent',
            self::NoFollowUp => 'Sans suite',
            self::Cancelled => 'Projet abandonné',
            self::Other => 'Autre',
        };
    }
}
