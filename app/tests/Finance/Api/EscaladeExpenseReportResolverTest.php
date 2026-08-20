<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Autorisation\Enum\PerimetreAutorisation;
use App\Tests\Finance\ExpenseReportApiTestCase;

/**
 * CA-3 (parties 2/3), §0.3.2 du plan : finalisation d'une note après décision du superviseur sur son
 * escalade — via l'endpoint de confort `POST .../finalize-escalade`, strictement le même service que
 * la commande planifiée.
 */
final class EscaladeExpenseReportResolverTest extends ExpenseReportApiTestCase
{
    public function testApprobationSuperviseurFinaliseApresRejeuTokenUsageUnique(): void
    {
        $this->configurerLimite('100.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.escalade-approuvee@itcotation.com');
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '250.00');
        $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);
        $recharge = $client->request('GET', '/api/expense_reports/' . $note['id'], $entete)->toArray();
        $demandeId = basename((string) $recharge['escalationRequest']);

        [$clientSuperviseur, $enteteSuperviseur] = $this->superviseurSurA('expense.superviseur-ca3@itcotation.com');
        $reponseApprobation = $clientSuperviseur->request('POST', '/api/demandes-escalade/' . $demandeId . '/approuver', $enteteSuperviseur + ['json' => []]);
        self::assertSame(201, $reponseApprobation->getStatusCode());

        // Confort UX : endpoint dédié plutôt que d'attendre le prochain passage de la commande.
        $reponseFinalize = $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/finalize-escalade', $entete + ['json' => []]);
        self::assertSame(201, $reponseFinalize->getStatusCode());
        $corps = $reponseFinalize->toArray(false);
        self::assertSame('approved', $corps['status']);
        self::assertNotNull($corps['ledgerEntry'] ?? null);

        // Deuxième appel : idempotent, aucune exception (statut n'est plus `submitted`, no-op).
        $reponseSeconde = $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/finalize-escalade', $entete + ['json' => []]);
        self::assertSame(201, $reponseSeconde->getStatusCode());
        self::assertSame('approved', $reponseSeconde->toArray(false)['status']);
    }

    public function testRejetSuperviseurRejetteLaNoteAvecMotif(): void
    {
        $this->configurerLimite('100.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.escalade-rejetee@itcotation.com');
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '250.00');
        $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);
        $recharge = $client->request('GET', '/api/expense_reports/' . $note['id'], $entete)->toArray();
        $demandeId = basename((string) $recharge['escalationRequest']);

        [$clientSuperviseur, $enteteSuperviseur] = $this->superviseurSurA('expense.superviseur-rejet@itcotation.com');
        $reponseRejet = $clientSuperviseur->request('POST', '/api/demandes-escalade/' . $demandeId . '/rejeter', $enteteSuperviseur + ['json' => ['motif' => 'Montant jugé excessif']]);
        self::assertSame(201, $reponseRejet->getStatusCode());

        $reponseFinalize = $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/finalize-escalade', $entete + ['json' => []]);
        self::assertSame(201, $reponseFinalize->getStatusCode());
        $corps = $reponseFinalize->toArray(false);
        self::assertSame('rejected', $corps['status']);
        self::assertSame('Montant jugé excessif', $corps['rejectionReason']);
    }

    public function testAutoApprobationSuperviseurImpossible(): void
    {
        // Rôle cumulant soumission ET approbation superviseur (RG-AUTZ-13 réutilisée à l'identique) —
        // la `LimiteAutorisation` doit être configurée pour CE rôle précisément (`ResolveurLimiteAutorisation`
        // résout par rôle effectif de l'utilisateur, pas par un rôle par défaut).
        $roleCumule = $this->roleSalarieEtSuperviseur();
        $this->configurerLimite('100.00', PerimetreAutorisation::Global, true, $roleCumule);

        [$client, $entete, $idEmploye] = $this->salarie('expense.auto-approbation@itcotation.com', role: $roleCumule);
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '250.00');
        $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);
        $recharge = $client->request('GET', '/api/expense_reports/' . $note['id'], $entete)->toArray();
        $demandeId = basename((string) $recharge['escalationRequest']);

        $reponse = $client->request('POST', '/api/demandes-escalade/' . $demandeId . '/approuver', $entete + ['json' => []]);

        self::assertSame(403, $reponse->getStatusCode());
    }

    private function roleSalarieEtSuperviseur(): \App\Securite\Entity\Role
    {
        $em = $this->em();
        $existant = $em->getRepository(\App\Securite\Entity\Role::class)->findOneBy(['nom' => 'Salarié Superviseur Cumulé Test']);
        if ($existant instanceof \App\Securite\Entity\Role) {
            return $existant;
        }

        $permissions = [];
        foreach ([['finance', 'expense_report_submit'], ['finance', 'expense_report_read_own'], ['finance', 'expense_report_approve'], ['autorisation', 'approuver']] as [$module, $action]) {
            $permission = $em->getRepository(\App\Securite\Entity\Permission::class)->findOneBy(['module' => $module, 'action' => $action]);
            self::assertInstanceOf(\App\Securite\Entity\Permission::class, $permission);
            $permissions[] = $permission;
        }

        $role = (new \App\Securite\Entity\Role())->setNom('Salarié Superviseur Cumulé Test');
        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }
        $em->persist($role);
        $em->flush();

        return $role;
    }
}
