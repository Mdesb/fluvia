<?php

declare(strict_types=1);

namespace App\Recouvrement\Port;

use App\Recouvrement\Dto\InitiationPaiementResultat;
use App\Recouvrement\Dto\ResultatConfirmationPaiement;
use Symfony\Component\Uid\Uuid;

/**
 * Port d'encaissement CB immédiat pour la résolution 1 clic (moteur générique de recouvrement,
 * ex-`App\Sport\Paiement\Port\EncaissementImmediatInterface`, généralisé). ⚠ PSP CB non nommé par les
 * sources — adaptateur stub par défaut.
 */
interface EncaissementImmediatInterface
{
    public function initierPaiement(Uuid $incidentId, int $montantCentimes): InitiationPaiementResultat;

    public function confirmerPaiement(string $referenceTransaction): ResultatConfirmationPaiement;
}
