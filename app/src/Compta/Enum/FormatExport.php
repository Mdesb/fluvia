<?php

declare(strict_types=1);

namespace App\Compta\Enum;

/** Format d'export comptable (RG-EXPORT-07), commuté par `ProfilExploitant::type`. */
enum FormatExport: string
{
    case PesV2Helios = 'PES_V2_Helios';
    case EtatRegie = 'EtatRegie';
    case Fec = 'FEC';
    case Ciel = 'CIEL';
    case Ebp = 'EBP';
    case Sage = 'Sage';
    case Cegid = 'Cegid';
}
