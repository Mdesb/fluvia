<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Tests\Finance\ExpenseReportApiTestCase;

/**
 * D3/D8 : cloisonnement à périmètre serveur, §0.2 du plan.
 *
 * - CA-7 : un salarié titulaire de `finance.expense_report_read_own` seul ne voit que ses propres
 *   notes (`PerimetreFinanceExtension`, mode « soi »).
 * - §0.2 point 1 : `establishment` hors des rattachements de l'employé -> 422 ; employé d'un collègue
 *   -> 403.
 * - §0.2 point 2 : un comptable (`finance.expense_report_post_to_ledger`, sans `read_own`) voit
 *   l'ensemble de l'établissement.
 */
final class CloisonnementExpenseReportTest extends ExpenseReportApiTestCase
{
    public function testEtablissementHorsDesRattachementsDeLEmployeRefuse422(): void
    {
        [$client, $entete, $idEmploye] = $this->salarie('expense.hors-rattachement@itcotation.com');

        // `Etablissement` (socle, `PerimetreEtablissementExtension`) n'est visible/référençable par IRI
        // que via une `Affectation` : sans elle, l'IRI de B ne se résoudrait même pas (400 avant
        // d'atteindre `CreateExpenseReportProcessor`). On accorde donc une `Affectation` socle sur B
        // (accès générique à l'établissement) SANS `RattachementEmploye` Personnel sur B — c'est
        // précisément la distinction que ce test vérifie (§0.2 point 1 du plan) : l'`Affectation` ne
        // suffit pas, il faut un rattachement Personnel actif à CET établissement précis.
        $em = $this->em();
        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB);
        $utilisateur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => 'expense.hors-rattachement@itcotation.com']);
        self::assertInstanceOf(Utilisateur::class, $utilisateur);
        $role = $em->getRepository(Role::class)->findOneBy(['nom' => \App\Finance\DataFixtures\ExpenseReportFixtures::ROLE_SALARIE]);
        self::assertInstanceOf(Role::class, $role);
        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etabB));
        $em->flush();

        // Établissement B : aucun `RattachementEmploye` de ce salarié ne le couvre.
        $reponse = $client->request('POST', '/api/expense_reports', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_B_NOM),
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'employee' => '/api/employes/' . $idEmploye,
            ],
        ]);

        self::assertSame(422, $reponse->getStatusCode());
        self::assertSame(0, $this->em()->getRepository(\App\Finance\ExpenseReport\Entity\ExpenseReport::class)->count([]));
    }

    public function testEmployeAutreUtilisateurRefuse403(): void
    {
        [$clientA, $enteteA] = $this->salarie('expense.createur@itcotation.com');
        [, , $idEmployeCollegue] = $this->salarie('expense.collegue@itcotation.com');

        $reponse = $clientA->request('POST', '/api/expense_reports', $enteteA + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'employee' => '/api/employes/' . $idEmployeCollegue,
            ],
        ]);

        self::assertSame(403, $reponse->getStatusCode());
    }

    public function testSalarieNeVoitQueSesPropresNotes(): void
    {
        [$clientSoi, $enteteSoi, $idEmployeSoi] = $this->salarie('expense.soi@itcotation.com');
        [$clientAutre, $enteteAutre, $idEmployeAutre] = $this->salarie('expense.autre@itcotation.com');

        $noteSoi = $this->creerNoteAvecLigne($clientSoi, $enteteSoi, $idEmployeSoi);
        $noteAutre = $this->creerNoteAvecLigne($clientAutre, $enteteAutre, $idEmployeAutre);

        $reponse = $clientSoi->request('GET', '/api/expense_reports', $enteteSoi)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'];
        $ids = array_map(static fn (array $n): string => $n['id'], $membres);

        self::assertContains($noteSoi['id'], $ids);
        self::assertNotContains($noteAutre['id'], $ids, 'finance.expense_report_read_own : un salarié ne voit que ses propres notes (CA-7).');
    }

    public function testComptableVoitToutesLesNotesDeLEtablissement(): void
    {
        [$clientSoi, $enteteSoi, $idEmployeSoi] = $this->salarie('expense.vu-par-comptable@itcotation.com');
        $note = $this->creerNoteAvecLigne($clientSoi, $enteteSoi, $idEmployeSoi);

        [$clientComptable, $enteteComptable] = $this->comptableSurA();
        $reponse = $clientComptable->request('GET', '/api/expense_reports', $enteteComptable)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'];
        $ids = array_map(static fn (array $n): string => $n['id'], $membres);

        self::assertContains($note['id'], $ids);
    }
}
