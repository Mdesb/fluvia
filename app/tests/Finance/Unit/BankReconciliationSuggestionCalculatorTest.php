<?php

declare(strict_types=1);

namespace App\Tests\Finance\Unit;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Service\BankReconciliationSuggestionCalculator;
use App\Tests\Finance\TreasuryApiTestCase;

/**
 * RG-TRE-03 (§0.7 du plan) — heuristique en lecture seule, aucune écriture.
 */
final class BankReconciliationSuggestionCalculatorTest extends TreasuryApiTestCase
{
    public function testToleranceMontantStricteAucunEcartAccepte(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);
        $this->creerLigneEcritureBancaireScellee('500.00', 'debit', new \DateTimeImmutable('2026-08-10'));

        $ligne = $this->ligneRelevePourMontant($client, $entete, $compte['id'], '2026-08-10', '500.01');

        $calculator = static::getContainer()->get(BankReconciliationSuggestionCalculator::class);
        \assert($calculator instanceof BankReconciliationSuggestionCalculator);

        self::assertSame([], $calculator->candidats($ligne));
    }

    public function testMontantExactEstCandidat(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);
        $this->creerLigneEcritureBancaireScellee('500.00', 'debit', new \DateTimeImmutable('2026-08-10'));

        $ligne = $this->ligneRelevePourMontant($client, $entete, $compte['id'], '2026-08-10', '500.00');

        $calculator = static::getContainer()->get(BankReconciliationSuggestionCalculator::class);
        \assert($calculator instanceof BankReconciliationSuggestionCalculator);

        $candidats = $calculator->candidats($ligne);
        self::assertCount(1, $candidats);
        self::assertSame(50000, $candidats[0]->amountCents);
    }

    public function testDeuxCandidatsMemeMontantMemeDateListesTousLesDeux(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);
        $this->creerLigneEcritureBancaireScellee('300.00', 'debit', new \DateTimeImmutable('2026-08-10'), 'Première écriture');
        $this->creerLigneEcritureBancaireScellee('300.00', 'debit', new \DateTimeImmutable('2026-08-10'), 'Seconde écriture');

        $ligne = $this->ligneRelevePourMontant($client, $entete, $compte['id'], '2026-08-10', '300.00');

        $calculator = static::getContainer()->get(BankReconciliationSuggestionCalculator::class);
        \assert($calculator instanceof BankReconciliationSuggestionCalculator);

        self::assertCount(2, $calculator->candidats($ligne));
    }

    public function testAucunCandidatSiComptesComptableNonRenseigne(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete, ['ledgerAccount' => null]);
        $this->creerLigneEcritureBancaireScellee('500.00', 'debit', new \DateTimeImmutable('2026-08-10'));

        $ligne = $this->ligneRelevePourMontant($client, $entete, $compte['id'], '2026-08-10', '500.00');

        $calculator = static::getContainer()->get(BankReconciliationSuggestionCalculator::class);
        \assert($calculator instanceof BankReconciliationSuggestionCalculator);

        self::assertSame([], $calculator->candidats($ligne));
    }

    /** @param array<string, mixed> $entete */
    private function ligneRelevePourMontant(Client $client, array $entete, string $idCompte, string $date, string $montant): BankStatementLine
    {
        $import = $this->creerImportManuel($client, $entete, $idCompte);
        $ligneJson = $this->creerLigneManuelle($client, $entete, $import['id'], $date, 'Ligne test', $montant);

        $ligne = $this->em()->getRepository(BankStatementLine::class)->find($ligneJson['id']);
        self::assertInstanceOf(BankStatementLine::class, $ligne);

        return $ligne;
    }
}
