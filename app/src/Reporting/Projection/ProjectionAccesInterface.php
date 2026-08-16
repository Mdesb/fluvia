<?php

declare(strict_types=1);

namespace App\Reporting\Projection;

use App\Reporting\ValueObject\Periode;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture seule du module Accès (RG-ACC-06, RG-ACC-04) — §2.1 plan-reporting.md. M7 lit les
 * mesures déjà produites (fréquentation, jauges FMI), ne recalcule jamais de jauge temps réel.
 */
interface ProjectionAccesInterface
{
    /** Fréquentation cumulée = COUNT des passages validés en entrée sur la période (compteur croissant). */
    public function frequentationCumulee(Uuid $etablissementId, Periode $periode): int;

    /**
     * Jauges FMI courantes des espaces de l'établissement, lues telles quelles (RG-ACC-04) — jamais
     * recalculées par M7.
     *
     * @return list<array{espace: string, libelle: string, valeurCourante: int, seuil: int, mode: string}>
     */
    public function jaugesFmi(Uuid $etablissementId): array;

    /**
     * Vrai si l'établissement est en défaut de remontée (signal de fraîcheur, RG-REPORT-11) : au
     * moins un contrôleur hors-ligne/hors-service, ou heartbeat plus ancien que `$seuilMinutes`
     * (⚠ heuristique documentée, Risque §9.7 plan-reporting.md — aucun contrôleur = jamais partiel).
     */
    public function etablissementHorsLigne(Uuid $etablissementId, int $seuilMinutes): bool;
}
