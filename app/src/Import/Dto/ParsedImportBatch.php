<?php

declare(strict_types=1);

namespace App\Import\Dto;

/**
 * Résultat du parsing d'un fichier importé (plan-import-i1.md §0.4). `$globalErrors` porte les défauts
 * qui empêchent de lire le fichier lui-même (fichier vide, illisible) — distincts des erreurs de
 * validation métier par ligne, portées par `RowImporterInterface::validate()`.
 */
final class ParsedImportBatch
{
    /**
     * @param list<ParsedImportRow> $rows
     * @param list<string>          $globalErrors
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $globalErrors = [],
    ) {
    }
}
