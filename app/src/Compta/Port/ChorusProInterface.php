<?php

declare(strict_types=1);

namespace App\Compta\Port;

use App\Compta\Entity\FactureB2G;
use App\Compta\Enum\StatutEnvoi;

/**
 * Port Chorus Pro (factures B2G, §4.7 spec). ⚠ HYPOTHÈSE — format exact non détaillé (§9 du plan).
 */
interface ChorusProInterface
{
    public function deposer(FactureB2G $facture): StatutEnvoi;
}
