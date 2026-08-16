<?php

declare(strict_types=1);

namespace App\Musee\Port;

use App\Musee\Entity\AllocationQuotaOTA;
use App\Musee\Entity\ReservationOTA;
use App\Musee\Entity\Reversement;

/**
 * Port applicatif du connecteur OTA (décision structurante n°4 du plan) : contrat métier agnostique
 * du protocole technique (Tiqets, Weezevent, FNAC-Spectacles…, non cadré — hors périmètre applicatif
 * musée). Même patron que `App\Acces\Port\ProjectionDroitInterface`/`StubProjectionDroit`.
 */
interface ConnecteurOtaInterface
{
    /** Notifie le partenaire d'une allocation de quota créée/modifiée. */
    public function notifierAllocation(AllocationQuotaOTA $allocation): void;

    /** Notifie le partenaire qu'un reversement a été calculé/versé. */
    public function notifierReversement(Reversement $reversement): void;

    /** Notifie le partenaire de la récupération d'un quota suite à un no-show OTA (CA-8). */
    public function notifierNoShowRecupere(ReservationOTA $reservationOta): void;
}
