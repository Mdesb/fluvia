<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Autorisation\Enum\PerimetreAutorisation;
use App\Compta\Entity\LettrageEcriture;
use App\Finance\DataFixtures\ExpenseReportFixtures;
use App\Tests\Finance\ExpenseReportApiTestCase;

/**
 * RG-EXP-05, §0.6 du plan : remboursement en une fois (pas de partiel v1), lettrage groupé à deux
 * lignes, précondition « déjà déversée en comptabilité ».
 */
final class ReimburseExpenseReportHandlerTest extends ExpenseReportApiTestCase
{
    public function testRemboursementTotalPasseAReimbursedEtLettre(): void
    {
        $this->configurerLimite('1000.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.remboursement@itcotation.com');
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '300.00');
        $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);

        [$clientComptable, $enteteComptable] = $this->comptableSurA();
        $reponse = $clientComptable->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/reimbursements', $enteteComptable + [
            'json' => [
                'date' => '2026-08-15',
                'amount' => '300.00',
                'method' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
                'reference' => 'VIR-2026-001',
            ],
        ]);

        self::assertSame(201, $reponse->getStatusCode());
        $remboursement = $reponse->toArray(false);
        self::assertNotNull($remboursement['reconciliationCode'] ?? null);

        $recharge = $clientComptable->request('GET', '/api/expense_reports/' . $note['id'], $enteteComptable)->toArray();
        self::assertSame('reimbursed', $recharge['status']);

        $lettrages = $this->em()->getRepository(LettrageEcriture::class)->findBy(['reconciliationCode' => $remboursement['reconciliationCode']]);
        self::assertCount(2, $lettrages);
    }

    public function testMontantDifferentDuTotalRejete422(): void
    {
        $this->configurerLimite('1000.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.remboursement-partiel@itcotation.com');
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '300.00');
        $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);

        [$clientComptable, $enteteComptable] = $this->comptableSurA();
        $reponse = $clientComptable->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/reimbursements', $enteteComptable + [
            'json' => [
                'date' => '2026-08-15',
                'amount' => '150.00',
                'method' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
            ],
        ]);

        self::assertSame(422, $reponse->getStatusCode());
    }

    public function testRemboursementAvantDeversementRefuse409(): void
    {
        $this->configurerLimite('1000.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.avant-deversement@itcotation.com');
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [
            'expenseNatureCode' => ExpenseReportFixtures::EXPENSE_NATURE_UNMAPPED,
        ], '300.00');
        $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);
        // CA-5 : approuvée mais mapping incomplet, `ledgerEntry` reste null.

        [$clientComptable, $enteteComptable] = $this->comptableSurA();
        $reponse = $clientComptable->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/reimbursements', $enteteComptable + [
            'json' => [
                'date' => '2026-08-15',
                'amount' => '300.00',
                'method' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
            ],
        ]);

        self::assertSame(409, $reponse->getStatusCode());
    }

    public function testDeuxiemeRemboursementRefuseParContrainteUnique(): void
    {
        $this->configurerLimite('1000.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.double-remboursement@itcotation.com');
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '300.00');
        $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);

        [$clientComptable, $enteteComptable] = $this->comptableSurA();
        $premier = $clientComptable->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/reimbursements', $enteteComptable + [
            'json' => ['date' => '2026-08-15', 'amount' => '300.00', 'method' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement')],
        ]);
        self::assertSame(201, $premier->getStatusCode());

        $second = $clientComptable->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/reimbursements', $enteteComptable + [
            'json' => ['date' => '2026-08-16', 'amount' => '300.00', 'method' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement')],
        ]);

        self::assertSame(409, $second->getStatusCode());
    }
}
