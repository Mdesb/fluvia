<?php

declare(strict_types=1);

namespace App\Reporting\Service\Export;

use App\Reporting\Enum\FormatExport;
use App\Reporting\Exception\GenerationExportNonSupporteeException;
use App\Reporting\Service\GenerateurExportInterface;

/**
 * Port XLSX non implémenté en MVP (§2.8/Risque §9.9 plan-reporting.md) : échoue proprement plutôt
 * que de planter silencieusement. Candidat V2 : `phpoffice/phpspreadsheet`.
 */
final class XlsxGenerateurExportStub implements GenerateurExportInterface
{
    public function format(): FormatExport
    {
        return FormatExport::Xlsx;
    }

    public function generer(array $entetes, array $lignes): ExportGenere
    {
        throw new GenerationExportNonSupporteeException('Format XLSX non disponible dans cette version, utiliser CSV.');
    }
}
