<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Dto;

/**
 * Résultat complet d'un parsing de relevé (§0.4 du plan — dégradation propre) : les lignes lisibles
 * **et** les motifs des lignes fautives (montant illisible, date invalide) qui n'interrompent jamais
 * l'import du fichier entier — chaque motif alimente `BankStatementImport.linesSkipped`.
 */
final class ParsedStatementBatch
{
    /**
     * @param list<ParsedStatementLine> $lines
     * @param list<string>              $skippedReasons
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $skippedReasons = [],
    ) {
    }
}
