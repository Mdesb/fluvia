<?php

declare(strict_types=1);

namespace App\Finance\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\ExpenseAccountMapping;
use App\Compta\Entity\Journal;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\SensCompte;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Stock\DataFixtures\StockFixtures;
use App\Stock\Entity\Fournisseur;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données de **test** du lot FIN-2 (`App\Finance\SupplierInvoice`) : permissions `finance.*`
 * accordées à l'administrateur, journaux `ACH`/`BNQ` (nouveaux codes, absents de `ComptaFixtures`,
 * cf. §7 point 9 du plan), compte de TVA déductible `4456xx` (absent, `ComptaFixtures` ne porte que
 * `4457100` « TVA collectée »), un mapping de charge de démonstration (réutilise le compte `627000`
 * déjà seedé), et deux fournisseurs Stock (actif/inactif) sur l'établissement A du socle.
 *
 * `401000` (« Fournisseurs ») et `512000` (« Banque ») sont **déjà** seedés par `ComptaFixtures` et
 * couvrent les préfixes `401`/`512` attendus par `ResolveurComptesSupplierInvoice` — aucun doublon créé
 * ici.
 */
final class FinanceFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    /** @var list<string> */
    public const ACTIONS = [
        'read', 'supplier_invoice_create', 'supplier_invoice_approve',
        'supplier_invoice_pay', 'supplier_invoice_dispute', 'manage',
    ];

    public const FOURNISSEUR_ACTIF = 'Fournisseur Finance Test SARL';
    public const FOURNISSEUR_INACTIF = 'Fournisseur Finance Inactif SARL';
    public const EXPENSE_NATURE_MAPPEE = 'default_supplier';
    public const EXPENSE_NATURE_NON_MAPPEE = 'unmapped_nature';
    public const COMPTE_TVA_DEDUCTIBLE = '445660';

    public function getDependencies(): array
    {
        return [SocleFixtures::class, ComptaFixtures::class, StockFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $perms = [];
        foreach (self::ACTIONS as $action) {
            $perm = $this->permissionNommee($manager, 'finance', $action);
            $manager->persist($perm);
            $perms[$action] = $perm;
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            foreach ($perms as $perm) {
                $roleAdmin->addPermission($perm);
            }
        }

        $profil = $manager->getRepository(ProfilExploitant::class)->findOneBy(['siren' => ComptaFixtures::PROFIL_SIREN]);
        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);

        if ($etabA instanceof Etablissement) {
        // ── LE BLOC DE DEMONSTRATION NE SE POSE QU'UNE FOIS ──────────────────────────────────
        //
        // Tout ce qui suit est un jeu de donnees coherent, pas un referentiel : le reposer sur une
        // base qui l'a deja ecraserait ce qui a ete corrige a la main depuis, ou le dupliquerait
        // pour les entites sans contrainte d'unicite -- silencieusement.
        //
        // Les permissions, les roles et les affectations restent AU-DESSUS : ils doivent etre
        // rejoues a chaque chargement, sans quoi un droit ajoute au code n'atteindrait jamais une
        // base existante.
        if ($manager->getRepository(\App\Stock\Entity\Fournisseur::class)->findOneBy([]) !== null) {
            $manager->flush();

            return;
        }
            $manager->persist((new Fournisseur())->setEtablissement($etabA)->setRaisonSociale(self::FOURNISSEUR_ACTIF)->setActif(true));
            $manager->persist((new Fournisseur())->setEtablissement($etabA)->setRaisonSociale(self::FOURNISSEUR_INACTIF)->setActif(false));
        }

        if (!$profil instanceof ProfilExploitant) {
            $manager->flush();

            return;
        }

        // Rattache aussi l'établissement B (test uniquement) : permet aux tests de cloisonnement
        // d'isoler la vérification « fournisseur hors périmètre » de celle du profil exploitant, sans
        // toucher `ComptaFixtures` (hors périmètre Finance).
        $etabB = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        if ($etabB instanceof Etablissement) {
            $profil->addEtablissementRattache($etabB);
        }

        $manager->persist((new Journal())->setProfilExploitant($profil)->setCode('ACH')->setLibelle('Journal des achats'));
        $manager->persist((new Journal())->setProfilExploitant($profil)->setCode('BNQ')->setLibelle('Journal des règlements'));

        $compteTvaDeductible = (new CompteComptable())
            ->setProfilExploitant($profil)
            ->setNumero(self::COMPTE_TVA_DEDUCTIBLE)
            ->setLibelle('TVA déductible sur biens et services')
            ->setSens(SensCompte::Debit);
        $manager->persist($compteTvaDeductible);

        $compteCharge = $manager->getRepository(CompteComptable::class)->findOneBy(['profilExploitant' => $profil->getId(), 'numero' => '627000']);
        $taux20 = $manager->getRepository(TauxTva::class)->findOneBy(['profilExploitant' => $profil->getId(), 'taux' => '20.00']);

        if ($compteCharge instanceof CompteComptable && $taux20 instanceof TauxTva) {
            $mapping = (new ExpenseAccountMapping())
                ->setBusinessProfile($profil)
                ->setExpenseNatureCode(self::EXPENSE_NATURE_MAPPEE)
                ->setExpenseAccount($compteCharge)
                ->setDeductibleVatRate($taux20)
                ->setActive(true);
            $manager->persist($mapping);
        }

        $manager->flush();
    }
}
