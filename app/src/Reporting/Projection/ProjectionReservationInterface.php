<?php

declare(strict_types=1);

namespace App\Reporting\Projection;

use App\Reporting\ValueObject\Periode;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture seule du module Réservation (M5) — §2.1 plan-reporting.md.
 */
interface ProjectionReservationInterface
{
    /** Taux de remplissage moyen (participants / capacité) sur la période, en pourcentage (0-100). */
    public function tauxRemplissage(Uuid $etablissementId, Periode $periode): string;

    /** Nombre de no-show qualifiés (`FacturationNoShow`) sur la période. */
    public function noShow(Uuid $etablissementId, Periode $periode): int;
}
