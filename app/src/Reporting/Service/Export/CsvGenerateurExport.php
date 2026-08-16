<?php

declare(strict_types=1);

namespace App\Reporting\Service\Export;

use App\Reporting\Enum\FormatExport;
use App\Reporting\Service\GenerateurExportInterface;

/**
 * Générateur CSV — RÉELLEMENT fonctionnel (§2.8 plan-reporting.md), `fputcsv` sur un flux mémoire
 * (même patron `StreamedResponse`/`fputcsv` que `App\Securite\Controller\ExportAuditController`).
 */
final class CsvGenerateurExport implements GenerateurExportInterface
{
    public function format(): FormatExport
    {
        return FormatExport::Csv;
    }

    public function generer(array $entetes, array $lignes): ExportGenere
    {
        $flux = fopen('php://temp', 'r+');
        \assert($flux !== false);

        fputcsv($flux, $entetes, ';', '"', '\\');
        foreach ($lignes as $ligne) {
            $valeurs = [];
            foreach ($entetes as $colonne) {
                $valeurs[] = $ligne[$colonne] ?? '';
            }
            fputcsv($flux, $valeurs, ';', '"', '\\');
        }

        rewind($flux);
        $contenu = stream_get_contents($flux);
        fclose($flux);

        return new ExportGenere($contenu !== false ? $contenu : '', 'csv');
    }
}
