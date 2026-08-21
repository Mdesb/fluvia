<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Port;

use App\Finance\Treasury\Dto\ParsedStatementBatch;
use App\Finance\Treasury\Entity\BankAccount;
use App\Finance\Treasury\Enum\BankStatementImportFormat;

/**
 * Port branchable de parsing de relevé bancaire (§0.4 du plan). `CsvBankStatementParser` est le
 * **seul** adaptateur construit par ce lot ; `Ofx`/`Camt053` restent des extensions futures — un format
 * sans adaptateur (`supports()` renvoie `false` pour tous les parsers enregistrés) est refusé en 422 par
 * `ImportBankStatementProcessor`, jamais 500 (§0.4).
 *
 * Signature légèrement élargie par rapport au plan littéral (`parse(): array` -> `ParsedStatementBatch`,
 * qui porte aussi les motifs de lignes ignorées) : nécessaire pour que le processor puisse renseigner
 * `BankStatementImport.linesSkipped`/`errorMessage` sans qu'une ligne fautive individuelle n'interrompe
 * le reste du fichier (dégradation propre, §0.4).
 */
interface BankStatementParserInterface
{
    public function supports(BankStatementImportFormat $format): bool;

    public function parse(string $content, BankAccount $account): ParsedStatementBatch;
}
