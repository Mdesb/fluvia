<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Finance\Treasury\Entity\BankStatementLine;
use App\Tests\Finance\TreasuryApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Import de relevé bancaire (§0.4/§0.5 du plan, CA-2).
 */
final class BankStatementImportTest extends TreasuryApiTestCase
{
    public function testMemeFichierReimporteRefuse409SansDupliquerLesLignes(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);
        $contenu = "date;libelle;montant;reference\n2026-08-01;Vente 1;100.00;REF1\n2026-08-02;Vente 2;50.00;REF2\n";
        $base64 = base64_encode($contenu);

        $import = $client->request('POST', '/api/bank_statement_imports', $entete + [
            'json' => ['bankAccount' => '/api/bank_accounts/' . $compte['id'], 'format' => 'csv', 'content' => $base64, 'fileName' => 'releve.csv'],
        ])->toArray();

        self::assertSame(2, $import['linesCreated']);
        self::assertSame(0, $import['linesSkipped']);

        $avant = (int) $this->em()->getRepository(BankStatementLine::class)->count([]);

        $reponse = $client->request('POST', '/api/bank_statement_imports', $entete + [
            'json' => ['bankAccount' => '/api/bank_accounts/' . $compte['id'], 'format' => 'csv', 'content' => $base64, 'fileName' => 'releve.csv'],
        ]);

        self::assertSame(409, $reponse->getStatusCode());
        self::assertSame($avant, (int) $this->em()->getRepository(BankStatementLine::class)->count([]), 'Aucune ligne dupliquée (CA-2).');
    }

    public function testFichierPartiellementRecouvrantIgnoreLesLignesDejaConnues(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);

        $premier = "date;libelle;montant;reference\n2026-08-01;A;10.00;R1\n2026-08-02;B;20.00;R2\n2026-08-03;C;30.00;R3\n";
        $importPremier = $client->request('POST', '/api/bank_statement_imports', $entete + [
            'json' => ['bankAccount' => '/api/bank_accounts/' . $compte['id'], 'format' => 'csv', 'content' => base64_encode($premier), 'fileName' => 'r1.csv'],
        ])->toArray();
        self::assertSame(3, $importPremier['linesCreated']);

        // Même 3 lignes + 2 nouvelles, contenu de fichier différent (donc pas bloqué au niveau 1).
        $second = "date;libelle;montant;reference\n2026-08-01;A;10.00;R1\n2026-08-02;B;20.00;R2\n2026-08-03;C;30.00;R3\n2026-08-04;D;40.00;R4\n2026-08-05;E;50.00;R5\n";
        $importSecond = $client->request('POST', '/api/bank_statement_imports', $entete + [
            'json' => ['bankAccount' => '/api/bank_accounts/' . $compte['id'], 'format' => 'csv', 'content' => base64_encode($second), 'fileName' => 'r2.csv'],
        ])->toArray();

        self::assertSame(2, $importSecond['linesCreated']);
        self::assertSame(3, $importSecond['linesSkipped']);
    }

    public function testLigneCsvIllisibleNInterrompPasLeReste(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);

        $contenu = "date;libelle;montant;reference\ndate-invalide;Ligne cassée;10.00;R1\n2026-08-02;Ligne valide;20.00;R2\n";
        $import = $client->request('POST', '/api/bank_statement_imports', $entete + [
            'json' => ['bankAccount' => '/api/bank_accounts/' . $compte['id'], 'format' => 'csv', 'content' => base64_encode($contenu), 'fileName' => 'r.csv'],
        ])->toArray();

        self::assertSame(1, $import['linesCreated']);
        self::assertSame(1, $import['linesSkipped']);
        self::assertNotNull($import['errorMessage']);
    }

    public function testFormatOfxNonEncoreSupporteRefuse422(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);

        $reponse = $client->request('POST', '/api/bank_statement_imports', $entete + [
            'json' => ['bankAccount' => '/api/bank_accounts/' . $compte['id'], 'format' => 'ofx', 'content' => base64_encode('<OFX></OFX>'), 'fileName' => 'r.ofx'],
        ]);

        self::assertSame(422, $reponse->getStatusCode());
    }

    public function testModeManuelToujoursDisponibleSansFichier(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);

        $import = $this->creerImportManuel($client, $entete, $compte['id']);
        self::assertSame('manual', $import['format']);
        self::assertNull($import['fileName'] ?? null);
        self::assertNull($import['contentHash'] ?? null);

        $ligne = $this->creerLigneManuelle($client, $entete, $import['id'], '2026-08-10', 'Frais bancaires', '-12.50');
        self::assertSame('unmatched', $ligne['status']);
        self::assertSame('-12.50', $ligne['amount']);
    }

    public function testAjoutLigneManuelleSurImportFichierRefuse409(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);
        $contenu = "date;libelle;montant;reference\n2026-08-01;A;10.00;R1\n";
        $import = $client->request('POST', '/api/bank_statement_imports', $entete + [
            'json' => ['bankAccount' => '/api/bank_accounts/' . $compte['id'], 'format' => 'csv', 'content' => base64_encode($contenu), 'fileName' => 'r.csv'],
        ])->toArray();

        $reponse = $client->request('POST', '/api/bank_statement_lines', $entete + [
            'json' => ['statementImport' => '/api/bank_statement_imports/' . $import['id'], 'operationDate' => '2026-08-05', 'label' => 'Intrus', 'amount' => '5.00'],
        ]);

        self::assertSame(409, $reponse->getStatusCode());
    }

    public function testCompteInactifAccepteImportMaisAucuneNouvelleSuggestion(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);

        // Écriture 512 scellée qui matcherait exactement si le compte était actif.
        $this->creerLigneEcritureBancaireScellee('100.00', 'debit', new \DateTimeImmutable('2026-08-01'));

        $client->request('PATCH', '/api/bank_accounts/' . $compte['id'], $this->entetePatch($entete) + [
            'json' => ['active' => false],
        ]);

        $contenu = "date;libelle;montant;reference\n2026-08-01;Vente;100.00;REF1\n";
        $reponseImport = $client->request('POST', '/api/bank_statement_imports', $entete + [
            'json' => ['bankAccount' => '/api/bank_accounts/' . $compte['id'], 'format' => 'csv', 'content' => base64_encode($contenu), 'fileName' => 'r.csv'],
        ]);
        self::assertSame(201, $reponseImport->getStatusCode(), 'L\'import reste possible sur un compte inactif (§7 cas limite).');
        $import = $reponseImport->toArray();
        self::assertSame(1, $import['linesCreated']);

        $application = new Application(self::$kernel);
        $command = $application->find('finance:treasury:suggerer-rapprochements');
        (new CommandTester($command))->execute([]);

        $lignes = $client->request('GET', '/api/bank_statement_lines', $entete + ['query' => ['statementImport' => $import['id']]])->toArray();
        self::assertSame('unmatched', $lignes['member'][0]['status'], 'Aucune suggestion sur un compte inactif.');

        // ⚠ LE FILTRE DOIT REDUIRE — et il ne le faisait pas. Le test ci-dessus filtre puis lit
        // member[0] : avec un filtre inerte il lisait la meme ligne, la fixture n en ayant qu une.
        // Il passait donc dans les deux cas.
        //
        // Un import INEXISTANT doit rendre zero. Mesure du 30/08 avant correction : 1 ligne. La
        // cause etait Finance/Treasury/Entity absent de mapping.paths, la liste que parcourt
        // AttributeFilterPass — le filtre etait declare, documente, accepte, et ignore.
        //
        // Une liste vide intrigue ; une liste pleine, jamais.
        $horsPortee = $client->request('GET', '/api/bank_statement_lines', $entete + [
            'query' => ['statementImport' => '00000000-0000-4000-8000-000000000000'],
        ])->toArray();
        self::assertCount(0, $horsPortee['member'], 'Le filtre statementImport ne reduit rien : il est declare mais jamais enregistre.');
    }
}
