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
    case ClotureZ = 'cloture_z';
    case ClotureMensuelle = 'cloture_mensuelle';
    case ClotureAnnuelle = 'cloture_annuelle';
}
