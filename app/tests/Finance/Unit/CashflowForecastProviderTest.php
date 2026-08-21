<?php

declare(strict_types=1);

namespace App\Tests\Finance\Unit;

use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Enum\BankStatementLineStatus;
use App\Finance\Treasury\Service\CashflowForecastCalculator;
use App\Organisation\Entity\Etablissement;
use App\Tests\Finance\TreasuryApiTestCase;

/**
 * RG-TRE-08 — `projectedBalance = position(aujourd'hui).balance + Σ entries[≤ horizon] −
 * Σ exits[≤ horizon]` : projection **arithmétique brute**, aucune pondération.
 */
final class CashflowForecastProviderTest extends TreasuryApiTestCase
{
    public function testProjectionArithmetiqueSansPonderation(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete, ['openingBalance' => '1000.00']);

        $import = $this->creerImportManuel($client, $entete, $compte['id']);
        $ligneJson = $this->creerLigneManuelle($client, $entete, $import['id'], date('Y-m-d'), 'Solde initial', '200.00');
        $ligne = $this->em()->getRepository(BankStatementLine::class)->find($ligneJson['id']);
        self::assertInstanceOf(BankStatementLine::class, $ligne);
        $ligne->setStatus(BankStatementLineStatus::Reconciled);
        $this->em()->flush();

        // Position actuelle = 1000,00 + 200,00 = 1200,00 €. Aucune entrée/sortie d'échéancier dans cette
        // fixture (aucune `SupplierInvoice`/`Facture`/`RemiseSepa` créée) : projection = position seule,
        // sans aucune pondération (RG-TRE-08 littéral).
        $etablissement = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $calculator = static::getContainer()->get(CashflowForecastCalculator::class);
        \assert($calculator instanceof CashflowForecastCalculator);

        $resultat = $calculator->projeter([$etablissement->getId()->toBinary()], new \DateTimeImmutable('today'), 30);

        self::assertSame(30, $resultat['horizonDays']);
        self::assertSame(120000, $resultat['projectedBalanceCents']);
    }
}
