<?php

declare(strict_types=1);

namespace App\Reporting\Projection;

use App\Reporting\ValueObject\Periode;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture seule du module Recouvrement (impayés) — §2.1 plan-reporting.md.
 */
interface ProjectionRecouvrementInterface
{
    /**
     * Montant et nombre des incidents impayés ouverts sur la période, pour un établissement.
     *
     * @return array{montant: string, nombre: int}
     */
    public function impayes(Uuid $etablissementId, Periode $periode): array;
}
