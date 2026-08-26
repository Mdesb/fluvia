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
    case ClotureZ = 'cloture_z';
    case ClotureMensuelle = 'cloture_mensuelle';
    case ClotureAnnuelle = 'cloture_annuelle';
}
