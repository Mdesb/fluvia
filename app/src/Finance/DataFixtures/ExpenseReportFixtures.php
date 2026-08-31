<?php

declare(strict_types=1);

namespace App\Finance\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Autorisation\Entity\OperationSensible;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\ExpenseAccountMapping;
use App\Compta\Entity\Journal;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\SensCompte;
use App\DataFixtures\SocleFixtures;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données de **test** du lot FIN-3 (`App\Finance\ExpenseReport`) : les 4 permissions
 * `finance.expense_report_*` (dont `approve`, défensive, §0.4 du plan — piège identifié : sans elle
 * `ServiceAutorisation::evaluer()` refuse dès l'étape 1, même sous plafond), l'`OperationSensible`
 * `finance.expense_report_approve`, le journal `NDF`, un compte salarié `421000` et un compte de
 * charge `625100` (+ mapping actif sur une nature de dépense de démonstration, une nature volontairement
 * **non** mappée pour CA-5), et trois rôles de test (« Salarié », « Comptable », « Superviseur »).
 *
 * §0.4 du plan — recommandation opérationnelle reprise ici : `finance.expense_report_approve` est
 * accordée au rôle « Salarié Note de frais Test » (qui détient déjà `finance.expense_report_submit`),
 * sans quoi RG-EXP-04 ne fonctionnerait jamais, y compris son cas le plus simple (CA-2).
 */
final class ExpenseReportFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public const ROLE_SALARIE = 'Salarié Note de frais Test';
    public const ROLE_COMPTABLE = 'Comptable Notes de frais Test';
    public const ROLE_SUPERVISEUR = 'Superviseur Notes de frais Test';

    public const OPERATION_CODE = 'finance.expense_report_approve';
    public const EXPENSE_NATURE_MAPPED = 'travel_expense_report_test';
    public const EXPENSE_NATURE_UNMAPPED = 'unmapped_expense_report_test';
    public const JOURNAL_CODE = 'NDF';
    public const COMPTE_EMPLOYE = '421000';
    public const COMPTE_CHARGE = '625100';

    public function getDependencies(): array
    {
        return [SocleFixtures::class, ComptaFixtures::class, FinanceFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // --- Permissions finance.expense_report_* (4, dont la défensive `approve`, §0.4) ---
        $permissions = [];
        foreach (['expense_report_submit', 'expense_report_read_own', 'expense_report_post_to_ledger', 'expense_report_approve'] as $action) {
            $existante = $manager->getRepository(Permission::class)->findOneBy(['module' => 'finance', 'action' => $action]);
            $permissions[$action] = $existante ?? $this->permissionNommee($manager, 'finance', $action);
            if ($existante === null) {
                $manager->persist($permissions[$action]);
            }
        }

        // `autorisation.approuver` : nécessaire pour le rôle Superviseur (approbation d'escalade),
        // pas encore créée dans cette chaîne de fixtures (FinanceApiTestCase ne charge pas
        // AutorisationApiTestCase) — créée défensivement si absente (même patron que
        // `PersonnelFixtures::permissionAcces()`).
        $permApprouver = $manager->getRepository(Permission::class)->findOneBy(['module' => 'autorisation', 'action' => 'approuver']);
        if ($permApprouver === null) {
            $permApprouver = $this->permissionNommee($manager, 'autorisation', 'approuver');
            $manager->persist($permApprouver);
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            // L'administrateur peut rejouer un déversement/consulter comme un comptable (tests
            // ledger-poster/reimbursement réutilisant `adminSurA()`) — jamais `submit`/`approve`
            // (l'admin n'est l'employé de personne, `EMPLOYE_SOI` échouerait de toute façon).
            $roleAdmin->addPermission($permissions['expense_report_post_to_ledger']);
        }

        // --- OperationSensible finance.expense_report_approve (§0.3/§0.4 du plan) ---
        $operation = $manager->getRepository(OperationSensible::class)->find(self::OPERATION_CODE);
        if ($operation === null) {
            $operation = (new OperationSensible())
                ->setCode(self::OPERATION_CODE)
                ->setLibelle('Notes de frais — Approbation graduée')
                ->setModuleAction(self::OPERATION_CODE)
                ->setActive(true);
            $manager->persist($operation);
        }

        // --- Rôles de test ---
        //
        // Cherchés avant d'être créés (D49) : `Role.nom` porte une unicité GLOBALE, et quatorze
        // fixtures créent des rôles. Un rechargement complet sur une base qui les a déjà échoue sur
        // « Duplicate entry » — ce qui est arrivé le 24/08 en préproduction, après que le chargement
        // eut tronqué les rattachements droits-rôles : trente-quatre rôles se sont retrouvés à zéro
        // droit. Le harnais de test ne le voyait pas, parce qu'il repart d'une base vide à chaque
        // classe et charge les fixtures sélectivement. Les deux mondes ne se croisaient jamais.
        $roleSalarie = $this->roleNomme($manager, self::ROLE_SALARIE);
        $roleSalarie->addPermission($permissions['expense_report_submit'])
            ->addPermission($permissions['expense_report_read_own'])
            ->addPermission($permissions['expense_report_approve']);
        $manager->persist($roleSalarie);

        $roleComptable = $this->roleNomme($manager, self::ROLE_COMPTABLE);
        $roleComptable->addPermission($permissions['expense_report_post_to_ledger']);
        $manager->persist($roleComptable);

        $roleSuperviseur = $this->roleNomme($manager, self::ROLE_SUPERVISEUR);
        $roleSuperviseur->addPermission($permApprouver);
        $manager->persist($roleSuperviseur);

        // --- Comptabilité : journal NDF, compte salarié 421, compte de charge + mapping ---
        $profil = $manager->getRepository(ProfilExploitant::class)->findOneBy(['siren' => ComptaFixtures::PROFIL_SIREN]);
        if (!$profil instanceof ProfilExploitant) {
            $manager->flush();

            return;
        }

        // ── LE BLOC DE DEMONSTRATION NE SE POSE QU'UNE FOIS ──────────────────────────────────
        //
        // La sentinelle est le JOURNAL, pas le mapping comptable : celui-ci n'est cree que si le taux
        // de TVA a 20 % existe, et une sentinelle conditionnelle n'est jamais posee quand la
        // condition manque -- le bloc se rejoue alors indefiniment.
        //
        // Elle vise le couple (profil, code) et non « un journal quelconque » : la comptabilite en
        // compte plusieurs, et une sentinelle large ferait sauter ce bloc.
        if ($manager->getRepository(Journal::class)
            ->findOneBy(['profilExploitant' => $profil, 'code' => self::JOURNAL_CODE]) !== null
        ) {
            $manager->flush();

            return;
        }

        $manager->persist((new Journal())->setProfilExploitant($profil)->setCode(self::JOURNAL_CODE)->setLibelle('Journal des notes de frais'));

        $compteEmploye = (new CompteComptable())
            ->setProfilExploitant($profil)
            ->setNumero(self::COMPTE_EMPLOYE)
            ->setLibelle('Personnel — Comptes courants')
            ->setSens(SensCompte::Credit);
        $manager->persist($compteEmploye);

        $compteCharge = (new CompteComptable())
            ->setProfilExploitant($profil)
            ->setNumero(self::COMPTE_CHARGE)
            ->setLibelle('Déplacements, missions et réceptions')
            ->setSens(SensCompte::Debit);
        $manager->persist($compteCharge);

        $taux20 = $manager->getRepository(TauxTva::class)->findOneBy(['profilExploitant' => $profil->getId(), 'taux' => '20.00']);
        if ($taux20 instanceof TauxTva) {
            $manager->persist((new ExpenseAccountMapping())
                ->setBusinessProfile($profil)
                ->setExpenseNatureCode(self::EXPENSE_NATURE_MAPPED)
                ->setExpenseAccount($compteCharge)
                ->setDeductibleVatRate($taux20)
                ->setActive(true));
        }
        // `EXPENSE_NATURE_UNMAPPED` : volontairement sans `ExpenseAccountMapping` (CA-5).

        $manager->flush();
    }

    /**
     * Le rôle portant ce nom, existant ou créé.
     *
     * **`addPermission` est idempotent de son côté** : la collection est une `ManyToMany` que Doctrine
     * dédoublonne. Rendre un rôle déjà présent puis lui rattacher les mêmes permissions ne produit
     * donc pas de doublon de rattachement — c'est ce qui permet de recharger sans rien casser.
     */
    private function roleNomme(ObjectManager $manager, string $nom): Role
    {
        $existant = $manager->getRepository(Role::class)->findOneBy(['nom' => $nom]);

        if ($existant instanceof Role) {
            return $existant;
        }

        $role = (new Role())->setNom($nom);
        $manager->persist($role);

        return $role;
    }

}
