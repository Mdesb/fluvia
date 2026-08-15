<?php

declare(strict_types=1);

namespace App\Compta\Port;

use App\Compta\Entity\DeclarationEReporting;
use App\Compta\Enum\StatutEnvoi;

/**
 * Port de dépôt e-reporting (US-L4-08). ⚠ HYPOTHÈSE — canal PDP (Plateforme de Dématérialisation
 * Partenaire) non nommé dans les sources (§9 du plan) : implémentation par défaut = stub.
 */
interface PdpInterface
{
    public function deposer(DeclarationEReporting $declaration): StatutEnvoi;
}
