<?php

declare(strict_types=1);

namespace App\Vente\Enum;

/**
 * Type d'opération scellée dans la chaîne NF525 (US-L2-11).
 */
enum TypeOperationScellee: string
{
    case Vente = 'vente';
    case Avoir = 'avoir';

    /**
     * D45 — correction de la ventilation d'un règlement (−X sur un moyen, +X sur un autre). Scellée
     * comme les autres : elle s'ajoute à la chaîne, elle ne réécrit rien.
     */
    case CorrectionReglement = 'correction_reglement';
    /**
     * D57 — **la clôture journalière n'est pas le Z**, et le dépôt les confondait.
     *
     * Le Z ferme une session de caisse : il compte du liquide et constate un écart. La journalière est
     * un arrêté de totaux cumulés, qui n'a besoin d'aucun tiroir. Les deux ont coïncidé tant que toute
     * vente passait par une caisse — et le jour où la vente directe est arrivée, ce n'était pas une
     * extension qu'il fallait, c'était une séparation.
     */
    case ClotureJournaliere = 'cloture_journaliere';
    case ClotureZ = 'cloture_z';
    case ClotureMensuelle = 'cloture_mensuelle';
    case ClotureAnnuelle = 'cloture_annuelle';
}
