<?php

declare(strict_types=1);

namespace App\Import\Port;

use App\Import\Dto\ParsedImportBatch;

/**
 * Port branchable de parsing d'un fichier de reprise (plan-import-i1.md §0.4). `CsvImportParser` est le
 * **seul** adaptateur construit par ce lot (I1) — XLSX reste un point ouvert de
 * SPEC-REPRISE-INITIALE.md §6, laissé à Maxime. Volontairement générique (contrairement au format
 * positionnel minimal de `App\Finance\Treasury\Service\CsvBankStatementParser`) : une fiche client a
 * une dizaine de champs, le parseur restitue donc chaque ligne par **nom de colonne**, jamais par
 * position — le mapping colonnes → champs métier est de la responsabilité du `RowImporter`, jamais du
 * parseur.
 */
interface ImportFileParserInterface
{
    public function supports(string $mimeType, string $fileName): bool;

    public function parse(string $rawContent): ParsedImportBatch;
}
