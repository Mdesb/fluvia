<?php

declare(strict_types=1);

namespace App\Compta\Adapter;

use App\Compta\Entity\DeclarationEReporting;
use App\Compta\Enum\StatutEnvoi;
use App\Compta\Port\PdpInterface;

/**
 * Adaptateur PDP par défaut — stub (⚠ HYPOTHÈSE, canal technique de dépôt e-reporting non nommé
 * dans les sources, §9 du plan). Marque la déclaration comme transmise (simulation).
 */
final class PdpStubAdapter implements PdpInterface
{
    public function deposer(DeclarationEReporting $declaration): StatutEnvoi
    {
        return StatutEnvoi::Transmis;
    }
}
