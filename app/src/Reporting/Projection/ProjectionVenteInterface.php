<?php

declare(strict_types=1);

namespace App\Reporting\Projection;

use App\Reporting\ValueObject\Periode;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture seule du module Vente (M2) — §2.1 plan-reporting.md. Aucune écriture, aucun recalcul
 * divergent (RG-M7-02) : le CA restitué par M7 est celui déjà encaissé/scellé par M2.
 */
interface ProjectionVenteInterface
{
    /** CA encaissé (ventes scellées) sur la période, pour un établissement. Retourne un decimal string. */
    public function caEncaisse(Uuid $etablissementId, Periode $periode): string;
}
