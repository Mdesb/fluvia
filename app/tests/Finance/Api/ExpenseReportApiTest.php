<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Tests\Finance\ExpenseReportApiTestCase;

/**
 * §4.3 point ouvert (salarié sans compte `Utilisateur`) et CA-1 (RG-EXP-02.1) : ligne sans justificatif
 * bloque la soumission, avec message identifiant la ligne fautive, statut inchangé.
 */
final class ExpenseReportApiTest extends ExpenseReportApiTestCase
{
    public function testSalarieSansUtilisateurNePeutPasSoumettre(): void
    {
        // Un tiers légitimement titulaire de `finance.expense_report_submit` (donc capable de créer
        // une note pour LUI-MÊME) tente de créer pour l'`Employe` sans compte `Utilisateur` : refusé
        // par `EMPLOYE_SOI` (403) — §4.3 point ouvert, un employé sans compte ne peut pas être « soi »
        // pour quiconque.
        [$client, $entete] = $this->salarie('expense.tiers@itcotation.com');
        $employe = $this->employeSansUtilisateur();

        $reponse = $client->request('POST', '/api/expense_reports', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM),
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'employee' => '/api/employes/' . $employe->getId(),
            ],
        ]);

        self::assertSame(403, $reponse->getStatusCode());
    }

    public function testLigneSansJustificatifBloqueLaSoumission(): void
    {
        [$client, $entete, $idEmploye] = $this->salarie('expense.ca1@itcotation.com');
        $this->configurerLimite('1000.00', \App\Autorisation\Enum\PerimetreAutorisation::Global, true);

        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, ['receiptUrl' => null]);

        $reponse = $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);

        self::assertSame(422, $reponse->getStatusCode());
        $corps = $reponse->toArray(false);
        self::assertStringContainsString('justificatif', $corps['detail'] ?? $corps['hydra:description'] ?? '');
        self::assertStringContainsString('Dépense de test', $corps['detail'] ?? $corps['hydra:description'] ?? '');

        $recharge = $client->request('GET', '/api/expense_reports/' . $note['id'], $entete)->toArray();
        self::assertSame('draft', $recharge['status']);
    }

    public function testCreationEnBrouillonAvecUneLigne(): void
    {
        [$client, $entete, $idEmploye] = $this->salarie('expense.creation@itcotation.com');

        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye);

        self::assertSame('draft', $note['status']);
        self::assertCount(1, $note['lines']);
        self::assertSame('40.00', $note['totalAmount']);
    }
}
