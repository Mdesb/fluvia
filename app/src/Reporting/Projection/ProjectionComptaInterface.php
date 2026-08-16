<?php

declare(strict_types=1);

namespace App\Reporting\Projection;

use App\Compta\Enum\TypeExploitant;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture seule du module Compta & Régie (M6) — §2.1 plan-reporting.md.
 */
interface ProjectionComptaInterface
{
    /** Solde théorique des caisses actuellement ouvertes de l'établissement (M7-01, CA-2). */
    public function fondDeCaisseTheorique(Uuid $etablissementId): string;

    /** Régime d'exploitant (RG-M6-01) porté par le `ProfilExploitant` couvrant l'établissement, si connu. */
    public function regimeExploitant(Uuid $etablissementId): ?TypeExploitant;

    /**
     * Vues isolées par régime (RAD/redevances DSP, état de régie publique) — jamais fusionnées dans
     * l'agrégat commun (RG-REPORT-09, §2.7 plan-reporting.md).
     *
     * @return array<string, mixed>
     */
    public function syntheseRegimeIsolee(Uuid $etablissementId): array;
}
