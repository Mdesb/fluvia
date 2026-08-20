<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Autorisation\Enum\PerimetreAutorisation;
use App\Finance\ExpenseReport\Entity\ExpenseReport;
use App\Tests\Finance\ExpenseReportApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * §0.3.2 du plan — la commande `finance:expense-reports:resoudre-escalades` fonctionne **sans
 * contexte HTTP actif** (`ContexteEtablissement::idActif()` retourne `null` en CLI, sans faire
 * échouer le rejeu, vérifié empiriquement). C'est le mécanisme qui garantit RG-EXP-04 même si
 * personne ne rappelle explicitement l'API après l'approbation superviseur.
 */
final class FinaliserEscaladesExpenseReportCommandTest extends ExpenseReportApiTestCase
{
    public function testCommandeTraiteLesNotesEnAttenteSansContexteHttp(): void
    {
        $this->configurerLimite('100.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.commande-cli@itcotation.com');
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '250.00');
        $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);
        $recharge = $client->request('GET', '/api/expense_reports/' . $note['id'], $entete)->toArray();
        $demandeId = basename((string) $recharge['escalationRequest']);

        [$clientSuperviseur, $enteteSuperviseur] = $this->superviseurSurA('expense.superviseur-cli@itcotation.com');
        $reponseApprobation = $clientSuperviseur->request('POST', '/api/demandes-escalade/' . $demandeId . '/approuver', $enteteSuperviseur + ['json' => []]);
        self::assertSame(201, $reponseApprobation->getStatusCode());

        // Aucun appel à `/finalize-escalade` : seule la commande planifiée doit finaliser la note.
        $application = new Application(self::$kernel);
        $command = $application->find('finance:expense-reports:resoudre-escalades');
        $tester = new CommandTester($command);
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());

        $noteApres = $this->em()->getRepository(ExpenseReport::class)->find($note['id']);
        self::assertInstanceOf(ExpenseReport::class, $noteApres);
        self::assertSame('approved', $noteApres->getStatus()->value);
        self::assertNotNull($noteApres->getLedgerEntry());
    }
}
