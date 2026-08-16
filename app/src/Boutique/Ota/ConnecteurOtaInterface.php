<?php

declare(strict_types=1);

namespace App\Boutique\Ota;

use App\Boutique\Entity\AllocationQuotaOTA;
use App\Boutique\Entity\PartenaireOTA;
use App\Boutique\Entity\ReversementOTA;
use App\Vente\Entity\Vente;

/**
 * Connecteur OTA générique (§2.3 plan-boutique.md, §0 décision n°7) : ne modélise que le
 * comportement observable (plafond, décrément partagé, reversement) ; l'intégration technique par
 * plateforme (Tiqets, Weezevent…) reste hors périmètre (⚠ Risque n°6 du plan).
 */
interface ConnecteurOtaInterface
{
    public function notifierAllocation(AllocationQuotaOTA $allocation): void;

    public function notifierVenteConfirmee(PartenaireOTA $partenaire, Vente $vente): void;

    public function notifierReversement(ReversementOTA $reversement): void;
}
