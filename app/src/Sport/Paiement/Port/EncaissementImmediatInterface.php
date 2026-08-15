<?php

declare(strict_types=1);

namespace App\Sport\Paiement\Port;

use App\Sport\Paiement\Dto\InitiationPaiementResultat;
use App\Sport\Paiement\Dto\ResultatConfirmationPaiement;
use Symfony\Component\Uid\Uuid;

/**
 * Port d'encaissement CB immédiat pour la résolution 1 clic (RG-SPORT-03, §2.3 du plan). Distinct de
 * PayFiP (M6, régie publique — non pertinent pour un club privé, spec §4.6). ⚠ PSP CB non nommé par
 * les sources — adaptateur stub par défaut (Risque n°4).
 */
interface EncaissementImmediatInterface
{
    public function initierPaiement(Uuid $incidentId, int $montantCentimes): InitiationPaiementResultat;

    public function confirmerPaiement(string $referenceTransaction): ResultatConfirmationPaiement;
}
