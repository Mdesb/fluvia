<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\DataFixtures\SocleFixtures;
use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Entity\TreasuryCashAlert;
use App\Finance\Treasury\Enum\BankStatementLineStatus;
use App\Tests\Finance\TreasuryApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Recentrage, relecture de la PR 2 (point 5) — les trois tâches de trésorerie ne traitent que les
 * établissements où Finance est allumé.
 *
 * Le même montage sur A et sur B : réglages (délai d'écart 0 jour, seuil d'alerte au-dessus du
 * solde), un compte bancaire, une ligne de relevé non rapprochée qui a exactement une écriture
 * candidate. Puis Finance allumé sur A, éteint sur B, et chaque commande passe une fois : seul A est
 * traité. Le témoin est A, dans le même passage.
 */
final class TreasuryTasksPerEstablishmentTest extends TreasuryApiTestCase
{
    /** @var array<string, string> id de la ligne de relevé, par établissement */
    private array $lines = [];

    public function testOnlyTheEstablishmentWhereFinanceIsOnIsProcessed(): void
    {
        $this->prepare(SocleFixtures::ETAB_A_NOM, $this->adminSurA(), '75.00');
        $this->prepare(SocleFixtures::ETAB_B_NOM, $this->adminSurB(), '85.00');

        $this->switchModule('finance', true, SocleFixtures::ETAB_A_NOM);
        $this->switchModule('finance', false, SocleFixtures::ETAB_B_NOM);

        foreach (['finance:treasury:suggerer-rapprochements', 'finance:treasury:detecter-ecarts', 'finance:treasury:verifier-seuils'] as $name) {
            (new CommandTester((new Application(self::$kernel))->find($name)))->execute([]);
        }
        $this->em()->clear();

        $lineA = $this->em()->getRepository(BankStatementLine::class)->find($this->lines[SocleFixtures::ETAB_A_NOM]);
        $lineB = $this->em()->getRepository(BankStatementLine::class)->find($this->lines[SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(BankStatementLine::class, $lineA);
        self::assertInstanceOf(BankStatementLine::class, $lineB);

        // suggerer-rapprochements
        self::assertSame(BankStatementLineStatus::Suggested, $lineA->getStatus(), 'A : rapprochement suggéré');
        self::assertSame(BankStatementLineStatus::Unmatched, $lineB->getStatus(), 'B : ligne non touchée');
        // detecter-ecarts
        self::assertNotNull($lineA->getDiscrepancyNotifiedAt(), 'A : écart signalé');
        self::assertNull($lineB->getDiscrepancyNotifiedAt(), 'B : rien signalé');
        // verifier-seuils
        $alerts = array_map(
            static fn (TreasuryCashAlert $a): string => (string) $a->getEstablishment()?->getId(),
            $this->em()->getRepository(TreasuryCashAlert::class)->findAll(),
        );
        self::assertContains($this->idEtablissement(SocleFixtures::ETAB_A_NOM), $alerts, 'A : alerte de trésorerie');
        self::assertNotContains($this->idEtablissement(SocleFixtures::ETAB_B_NOM), $alerts, 'B : aucune alerte');
    }

    /**
     * Les fixtures ne donnent un plan comptable (compte 512) qu'à A : B ne peut pas ouvrir de compte
     * bancaire par l'API. Le compte de B est donc ouvert par A, puis rattaché à B en base — ce qui ne
     * change rien à ce que mesurent les commandes, qui lisent l'établissement du compte.
     *
     * @param array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>, 2: string} $as
     */
    private function prepare(string $establishment, array $as, string $amount): void
    {
        [$client, $headers, $id] = $as;
        $client->request('POST', '/api/treasury_settings', $headers + ['json' => [
            'establishment' => '/api/etablissements/' . $id,
            'unmatchedAlertDelayDays' => 0,
            'cashAlertThresholdCents' => 100000000,
            'cashAlertHorizonDays' => 30,
        ]]);
        self::assertResponseIsSuccessful('réglages de ' . $establishment);

        [$clientA, $headersA] = $this->adminSurA();
        $account = $this->creerCompteBancaire($clientA, $headersA, ['label' => 'Compte ' . $establishment]);
        self::assertArrayHasKey('id', $account, 'compte bancaire de ' . $establishment . ' : ' . json_encode($account));
        $import = $this->creerImportManuel($clientA, $headersA, $account['id']);
        $date = new \DateTimeImmutable('2026-08-01');
        $this->creerLigneEcritureBancaireScellee($amount, 'debit', $date, 'Encaissement ' . $establishment);
        $line = $this->creerLigneManuelle($clientA, $headersA, $import['id'], $date->format('Y-m-d'), 'Virement ' . $establishment, $amount);
        self::assertArrayHasKey('id', $line, 'ligne de ' . $establishment);
        $this->lines[$establishment] = $line['id'];

        // Le seuil ne se juge qu'aux dates des mouvements prévus : une facture fournisseur approuvée,
        // échue dans l'horizon, en donne un.
        $invoice = $this->creerFactureAvecLigne($clientA, $headersA, $this->idFournisseur(\App\Finance\DataFixtures\FinanceFixtures::FOURNISSEUR_ACTIF), [
            'unitPriceExclTax' => '10.00',
            'quantity' => '1.000',
        ], 'FACT-RECENTRAGE-' . $amount);
        $clientA->request('POST', '/api/finance/supplier-invoices/' . $invoice['id'] . '/approve', $headersA);

        $target = $this->em()->getRepository(\App\Organisation\Entity\Etablissement::class)->find($id);
        $entity = $this->em()->getRepository(\App\Finance\Treasury\Entity\BankAccount::class)->find($account['id']);
        $supplierInvoice = $this->em()->getRepository(\App\Finance\SupplierInvoice\Entity\SupplierInvoice::class)->find($invoice['id']);
        self::assertNotNull($entity);
        self::assertNotNull($supplierInvoice);
        $entity->setEstablishment($target);
        $supplierInvoice->setEstablishment($target)->setDueDate(new \DateTimeImmutable('today +5 days'));
        $this->em()->flush();
    }
}
