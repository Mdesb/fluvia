<?php

declare(strict_types=1);

namespace App\Stay\Enum;

/**
 * Pourquoi une consommation a été rattachée à un séjour — ou pourquoi elle ne l'a pas été (ACT-3).
 *
 * **L'échec est typé, pas silencieux.** Un résolveur qui renverrait simplement `null` mettrait dans le
 * même sac « ce client n'est pas en séjour » — le cas normal, celui du passant qui paie son entrée —
 * et « ce client a deux séjours ouverts et je ne sais pas lequel débiter » — une anomalie
 * d'exploitation qui demande une action humaine. Le premier ne mérite pas une ligne de journal, le
 * second en mérite une.
 */
enum StayResolutionReason: string
{
    /** Un séjour ouvert et un seul : la consommation lui est rattachée. */
    case Matched = 'matched';

    /** Aucun séjour ouvert — cas nominal d'un client de passage, pas une erreur. */
    case NoOpenStay = 'no_open_stay';

    /**
     * Plusieurs séjours ouverts pour ce client dans cet établissement.
     *
     * **On ne devine pas.** Débiter le mauvais compte se découvre au départ, devant le client, et se
     * corrige par un geste commercial. Ne pas débiter se découvre aussi au départ, mais se corrige par
     * une ligne ajoutée à la main. Entre les deux, le choix n'est pas difficile.
     */
    case Ambiguous = 'ambiguous';
}
