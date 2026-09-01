<?php

declare(strict_types=1);

namespace App\Import\Dto;

/**
 * Une ligne de données d'un fichier importé, restituée par `App\Import\Port\ImportFileParserInterface`
 * (plan-import-i1.md §0.4). `$lineNumber` est le numéro **tel que l'utilisateur le voit dans son
 * tableur** (base 1, en-tête = ligne 1) — les messages d'erreur d'un `RowImporter` doivent le
 * réutiliser tel quel, jamais un index recalculé.
 */
final class ParsedImportRow
{
    /** @param array<string,string> $columns clés normalisées (minuscules, espaces retirés) */
    public function __construct(
        public readonly int $lineNumber,
        public readonly array $columns,
    ) {
    }
}
