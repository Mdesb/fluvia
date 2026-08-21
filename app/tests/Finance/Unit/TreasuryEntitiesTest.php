<?php

declare(strict_types=1);

namespace App\Tests\Finance\Unit;

use App\Finance\Treasury\Entity\BankAccount;
use App\Finance\Treasury\Entity\BankStatementImport;
use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Entity\TreasurySettings;
use App\Finance\Treasury\Enum\BankStatementImportFormat;
use App\Finance\Treasury\Enum\BankStatementImportStatus;
use App\Finance\Treasury\Enum\BankStatementLineStatus;
use PHPUnit\Framework\TestCase;

/** T1 du plan — tests unitaires d'entité (valeurs par défaut, accesseurs), sans base de données. */
final class TreasuryEntitiesTest extends TestCase
{
    public function testBankAccountValeursParDefaut(): void
    {
        $compte = new BankAccount();

        self::assertTrue($compte->isActive());
        self::assertSame('', $compte->getIbanLast4());
        self::assertSame('', $compte->getIbanClear());
        self::assertNull($compte->getIbanCipher());
        self::assertSame('0.00', $compte->getOpeningBalance());
    }

    public function testBankStatementImportValeursParDefaut(): void
    {
        $import = new BankStatementImport();

        self::assertSame(BankStatementImportStatus::Imported, $import->getStatus());
        self::assertSame(0, $import->getLinesCreated());
        self::assertSame(0, $import->getLinesSkipped());
        self::assertNull($import->getContentHash());
    }

    public function testBankStatementLineValeursParDefaut(): void
    {
        $ligne = new BankStatementLine();

        self::assertSame(BankStatementLineStatus::Unmatched, $ligne->getStatus());
        self::assertNull($ligne->getReconciliationCode());
        self::assertNull($ligne->getMatchedLedgerLine());
        self::assertNull($ligne->getDiscrepancyNotifiedAt());
    }

    public function testTreasurySettingsValeursParDefaut(): void
    {
        $settings = new TreasurySettings();

        self::assertSame(TreasurySettings::DEFAULT_UNMATCHED_ALERT_DELAY_DAYS, $settings->getUnmatchedAlertDelayDays());
        self::assertSame(TreasurySettings::DEFAULT_MATCHING_WINDOW_DAYS, $settings->getMatchingWindowDays());
    }

    public function testBankStatementImportFormatPorteQuatreValeurs(): void
    {
        self::assertSame('csv', BankStatementImportFormat::Csv->value);
        self::assertSame('ofx', BankStatementImportFormat::Ofx->value);
        self::assertSame('camt053', BankStatementImportFormat::Camt053->value);
        self::assertSame('manual', BankStatementImportFormat::Manual->value);
    }
}
