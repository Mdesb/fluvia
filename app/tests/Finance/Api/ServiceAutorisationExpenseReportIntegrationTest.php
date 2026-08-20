<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Autorisation\Enum\PerimetreAutorisation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Tests\Finance\ExpenseReportApiTestCase;

/**
 * §0.4 du plan — **régression critique** : `ServiceAutorisation::evaluer()` commence par
 * `isGranted('PERM', 'finance.expense_report_approve')` **avant** toute résolution de
 * `LimiteAutorisation`. Un rôle sans cette permission (même avec `finance.expense_report_submit`) voit
 * sa soumission `Refuse` dès l'étape 1, **même pour un montant très inférieur à tout plafond** —
 * preuve explicite de la nécessité du seed §0.4 (`ExpenseReportFixtures` l'accorde par défaut au rôle
 * « Salarié Note de frais Test », ce test construit délibérément un rôle qui en est privé).
 */
final class ServiceAutorisationExpenseReportIntegrationTest extends ExpenseReportApiTestCase
{
    public function testPermissionApprouveManquanteRefuseMemeSousPlafond(): void
    {
        $roleSansApprove = $this->roleSalarieSansApprove();
        // Plafond très haut : un montant de 1,00 € serait « sous plafond » sans le piège §0.4.
        $this->configurerLimite('9999.00', PerimetreAutorisation::Global, true, $roleSansApprove);

        [$client, $entete, $idEmploye] = $this->salarie('expense.sans-approve@itcotation.com', role: $roleSansApprove);
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '1.00');

        $reponse = $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);

        self::assertSame(201, $reponse->getStatusCode(), 'La soumission elle-même réussit (RG-EXP-04) : c\'est la décision Autorisation qui est Refuse.');
        $corps = $reponse->toArray(false);
        self::assertSame('rejected', $corps['status'], 'Sans finance.expense_report_approve, ServiceAutorisation refuse dès l\'étape 1 (droit binaire), même très sous plafond.');
        self::assertNotNull($corps['rejectionReason'] ?? null);
    }

    private function roleSalarieSansApprove(): Role
    {
        $em = $this->em();
        $existant = $em->getRepository(Role::class)->findOneBy(['nom' => 'Salarié Sans Approve Test']);
        if ($existant instanceof Role) {
            return $existant;
        }

        $permSubmit = $em->getRepository(Permission::class)->findOneBy(['module' => 'finance', 'action' => 'expense_report_submit']);
        $permReadOwn = $em->getRepository(Permission::class)->findOneBy(['module' => 'finance', 'action' => 'expense_report_read_own']);
        self::assertInstanceOf(Permission::class, $permSubmit);
        self::assertInstanceOf(Permission::class, $permReadOwn);

        $role = (new Role())->setNom('Salarié Sans Approve Test');
        // Volontairement PAS `finance.expense_report_approve` — c'est tout le point de ce test.
        $role->addPermission($permSubmit)->addPermission($permReadOwn);
        $em->persist($role);
        $em->flush();

        return $role;
    }
}
