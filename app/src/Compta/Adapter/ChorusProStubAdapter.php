<?php

declare(strict_types=1);

namespace App\Compta\Adapter;

use App\Compta\Entity\FactureB2G;
use App\Compta\Enum\StatutEnvoi;
use App\Compta\Port\ChorusProInterface;

/** Adaptateur Chorus Pro par défaut — stub (⚠ HYPOTHÈSE, format exact non détaillé, §9 du plan). */
final class ChorusProStubAdapter implements ChorusProInterface
{
    public function deposer(FactureB2G $facture): StatutEnvoi
    {
        return StatutEnvoi::Transmis;
    }
}
