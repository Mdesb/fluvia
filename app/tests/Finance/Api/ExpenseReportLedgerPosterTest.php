<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Autorisation\Enum\PerimetreAutorisation;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\ExpenseAccountMapping;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Finance\DataFixtures\ExpenseReportFixtures;
use App\Tests\Finance\ExpenseReportApiTestCase;

/**
 * RG-EXP-06, §0.5 du plan : déversement comptable découplé de l'approbation métier (CA-5), rejeu après
 * correction du mapping, ventilation HT/TVA par ligne (jamais de taux « emprunté » au mapping).
 */
final class ExpenseReportLedgerPosterTest extends ExpenseReportApiTestCase
{
    public function testMappingChargeIncompletBloqueDeversementSansAnnulerLApprobation(): void
    {
        $this->configurerLimite('1000.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.ca5@itcotation.com');
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [
            'expenseNatureCode' => ExpenseReportFixtures::EXPENSE_NATURE_UNMAPPED,
        ], '30.00');

        $reponse = $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);

        self::assertSame(201, $reponse->getStatusCode());
        $corps = $reponse->toArray(false);
        self::assertSame('approved', $corps['status'], 'CA-5 : l\'approbation métier a déjà eu lieu via App\\Autorisation, mapping incomplet ou non.');
        self::assertNull($corps['ledgerEntry'] ?? null, 'Aucune écriture construite tant que le mapping est incomplet.');
    }

    public function testDeversementRejouableApresCorrectionDuMapping(): void
    {
        $this->configurerLimite('1000.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.rejouable@itcotation.com');
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [
            'expenseNatureCode' => ExpenseReportFixtures::EXPENSE_NATURE_UNMAPPED,
        ], '30.00');
        $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);

        // Correction a posteriori du mapping de charge (comptable/admin).
        $em = $this->em();
        $profil = $em->getRepository(ProfilExploitant::class)->findOneBy(['siren' => \App\Compta\DataFixtures\ComptaFixtures::PROFIL_SIREN]);
        self::assertInstanceOf(ProfilExploitant::class, $profil);
        $compteCharge = $em->getRepository(CompteComptable::class)->findOneBy(['profilExploitant' => $profil->getId(), 'numero' => ExpenseReportFixtures::COMPTE_CHARGE]);
        self::assertInstanceOf(CompteComptable::class, $compteCharge);
        $taux20 = $em->getRepository(TauxTva::class)->findOneBy(['profilExploitant' => $profil->getId(), 'taux' => '20.00']);
        self::assertInstanceOf(TauxTva::class, $taux20);
        $em->persist((new ExpenseAccountMapping())
            ->setBusinessProfile($profil)
            ->setExpenseNatureCode(ExpenseReportFixtures::EXPENSE_NATURE_UNMAPPED)
            ->setExpenseAccount($compteCharge)
            ->setDeductibleVatRate($taux20)
            ->setActive(true));
        $em->flush();

        [$clientComptable, $enteteComptable] = $this->comptableSurA();
        $reponse = $clientComptable->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/post-to-ledger', $enteteComptable + ['json' => []]);

        self::assertSame(201, $reponse->getStatusCode());
        $corps = $reponse->toArray(false);
        self::assertSame('approved', $corps['status']);
        self::assertNotNull($corps['ledgerEntry'] ?? null);
    }

    public function testLigneSansTauxTvaIntegralementDebiteeSansDeduction(): void
    {
        $this->configurerLimite('1000.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.sans-tva@itcotation.com');
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, ['vatRate' => null], '30.00');
        $reponse = $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);
        self::assertSame(201, $reponse->getStatusCode());
        $corps = $reponse->toArray(false);
        self::assertSame('approved', $corps['status']);

        $ecriture = $this->em()->getRepository(EcritureComptable::class)->find(basename((string) $corps['ledgerEntry']));
        self::assertInstanceOf(EcritureComptable::class, $ecriture);
        self::assertTrue($ecriture->estEquilibree());
        self::assertSame(3000, $ecriture->totalDebitCentimes());

        $ligneCharge = null;
        foreach ($ecriture->getLignes() as $ligne) {
            if ($ligne->getDebitCentimes() > 0) {
                $ligneCharge = $ligne;
            }
        }
        self::assertNotNull($ligneCharge, 'Aucune ligne TVA déduite : la totalité TTC débite le compte de charge en une seule ligne.');
        self::assertSame(3000, $ligneCharge->getDebitCentimes());
    }

    public function testLigneAvecTauxTvaVentileHtEtTva(): void
    {
        $this->configurerLimite('1000.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.avec-tva@itcotation.com');
        // 120,00 € TTC à 20 % -> 100,00 € HT + 20,00 € TVA.
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '120.00');
        $reponse = $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);
        self::assertSame(201, $reponse->getStatusCode());
        $corps = $reponse->toArray(false);

        $ecriture = $this->em()->getRepository(EcritureComptable::class)->find(basename((string) $corps['ledgerEntry']));
        self::assertInstanceOf(EcritureComptable::class, $ecriture);
        self::assertTrue($ecriture->estEquilibree());
        self::assertSame(12000, $ecriture->totalDebitCentimes());

        $debits = [];
        foreach ($ecriture->getLignes() as $ligne) {
            if ($ligne->getDebitCentimes() > 0) {
                $debits[] = $ligne->getDebitCentimes();
            }
        }
        sort($debits);
        self::assertSame([2000, 10000], $debits, 'Débit HT (10000) et débit TVA déductible (2000) sur deux lignes distinctes.');
    }
}
