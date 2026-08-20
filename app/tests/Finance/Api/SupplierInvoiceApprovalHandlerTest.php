<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Finance\DataFixtures\FinanceFixtures;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Tests\Finance\FinanceApiTestCase;

/**
 * CA-4 (US-SINV-04/05, RG-SINV-05) : validation -> `to_pay`, écriture équilibrée scellée, idempotence.
 * CA-5 (US-SINV-05, RG-SINV-06) : mapping de charge incomplet -> 422, aucune écriture créée.
 */
final class SupplierInvoiceApprovalHandlerTest extends FinanceApiTestCase
{
    public function testValidationGenereEcritureEquilibreeEtScelle(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [], 'FACT-APPROVE-001');

        $reponse = $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);

        self::assertSame(201, $reponse->getStatusCode());
        $facture = $reponse->toArray();
        self::assertSame('to_pay', $facture['status']);
        self::assertNotNull($facture['ledgerEntry']);

        $ecriture = $this->entite(\App\Compta\Entity\EcritureComptable::class, ['id' => basename((string) $facture['ledgerEntry'])]);
        self::assertTrue($ecriture->estEquilibree());
        self::assertTrue($ecriture->estScellee());
        self::assertSame($ecriture->totalDebitCentimes(), $ecriture->totalCreditCentimes());
        self::assertSame(48000, $ecriture->totalDebitCentimes(), '100 x 4,00 € HT + 20% TVA = 480,00 € TTC.');
    }

    public function testDeuxiemeValidationRejetee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [], 'FACT-APPROVE-002');

        $premiere = $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);
        self::assertSame(201, $premiere->getStatusCode());

        $avant = $this->compterEcritures();

        $seconde = $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);

        // Idempotence (§0.7 du plan) : la garde `status !== Draft` évaluée fail-fast rejette la
        // seconde tentative — aucune seconde `EcritureComptable` n'est créée.
        self::assertSame(409, $seconde->getStatusCode());
        self::assertSame($avant, $this->compterEcritures());
    }

    public function testMappingChargeIncompletBloqueValidation(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);

        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [
            // Nature de charge SANS `ExpenseAccountMapping` (FinanceFixtures ne mappe que
            // `EXPENSE_NATURE_MAPPEE`) — CA-5.
            'expenseNatureCode' => FinanceFixtures::EXPENSE_NATURE_NON_MAPPEE,
        ], 'FACT-APPROVE-003');

        $avantEcritures = $this->compterEcritures();

        $reponse = $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);

        self::assertSame(422, $reponse->getStatusCode());
        self::assertSame($avantEcritures, $this->compterEcritures(), 'Aucune écriture ne doit être créée quand le mapping de charge est incomplet.');

        $recharge = $this->entite(SupplierInvoice::class, ['id' => $facture['id']]);
        self::assertSame('draft', $recharge->getStatus()->value, 'La facture doit rester en brouillon (CA-5).');
    }

    private function compterEcritures(): int
    {
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return (int) $em->getRepository(\App\Compta\Entity\EcritureComptable::class)->count([]);
    }
}
