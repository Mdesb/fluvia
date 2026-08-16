<?php

declare(strict_types=1);

namespace App\Reporting\Service;

use App\Reporting\Enum\FormatExport;
use App\Reporting\Service\Export\ExportGenere;

/**
 * Port de génération d'un export (§2.8 plan-reporting.md), une implémentation par `FormatExport`,
 * agrégées par `ResolveurGenerateurExport` (tag `reporting.generateur_export`, même patron que
 * `App\Reservation\Facturation\ResolveurStrategieFacturation`).
 */
interface GenerateurExportInterface
{
    public function format(): FormatExport;

    /**
     * @param list<string>                       $entetes
     * @param list<array<string, string|int|null>> $lignes
     *
     * @throws \App\Reporting\Exception\GenerationExportNonSupporteeException si le format n'est pas implémenté (PDF/XLSX MVP)
     */
    public function generer(array $entetes, array $lignes): ExportGenere;
}
