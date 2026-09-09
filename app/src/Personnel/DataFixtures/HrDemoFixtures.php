<?php

declare(strict_types=1);

namespace App\Personnel\DataFixtures;

use App\Organisation\Entity\Etablissement;
use App\Personnel\Entity\AffectationTravail;
use App\Personnel\Entity\CreneauTravail;
use App\Personnel\Entity\Employe;
use App\Personnel\Entity\Qualification;
use App\Personnel\Entity\RattachementEmploye;
use App\Personnel\Enum\StatutAffectationTravail;
use App\Personnel\Enum\StatutEmploye;
use App\Personnel\Enum\TypeContrat;
use App\Personnel\Enum\TypeQualification;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Les employés de démonstration — et pourquoi ils ne sont PAS dans `PersonnelFixtures`.
 *
 * ── LE CONSTAT QUI REND CETTE CLASSE NÉCESSAIRE ─────────────────────────────────────────────────
 *
 * Au 08/09, `grep -rn 'new Employe(' app/src` rendait **zéro ligne**. Témoin positif : la même
 * commande rendait dix lignes sous `app/tests`, l'instrument n'était donc pas muet. Aucune fixture ne
 * semait d'employé : tout écran du module Personnel se démontrait sur une **liste vide**, et un écran
 * vert sur une liste vide ne prouve rien.
 *
 * ── ⚠ POURQUOI PAS DANS `PersonnelFixtures` ─────────────────────────────────────────────────────
 *
 * `PersonnelApiTestCase::setUp()` recharge `PersonnelFixtures` **avant chaque test** des neuf fichiers
 * de `app/tests/Personnel/`. Y semer des employés déplacerait la ligne de base de toute la suite.
 *
 * Le cas précis qui casserait : `LireSoiPersonnelTest` construit **son propre** `Employe` lié au compte
 * `EMAIL_EMPLOYE_SOI` et le retrouve par `findOneBy(['utilisateur' => …])`. Un second employé lié au
 * même utilisateur rendrait ce `findOneBy` **non déterministe** — un test qui passe ou non selon
 * l'ordre d'insertion, c'est-à-dire le pire genre de rouge.
 *
 * Cette classe est donc chargée par `doctrine:fixtures:load` (démonstration, préproduction) et
 * **ignorée** par le harnais, qui charge ses fixtures nommément.
 *
 *   > La contrepartie est assumée : la démonstration et les tests ne voient pas la même base.
 *
 * ── LES CAS DE BORD SONT LE SUJET, PAS LE DÉCOR ─────────────────────────────────────────────────
 *
 * Six employés, choisis pour que chaque état que le produit sait produire soit visible sans saisie :
 * un rattaché à A, un à B, un aux **deux** (le multi-site que `CIBLES_VIA_EMPLOYE` traite à part), un
 * **orphelin** (aucun rattachement — visible du seul porteur de `personnel.gerer_employe`, et le seul
 * qui ne peut pas recevoir de badge, RG-PERSO-09), un **suspendu**, un **sorti**.
 *
 * Quatre qualifications échelonnées : **expirée** (J−30), **expirant sous 14 jours** (J+7),
 * **expirant sous 30 mais pas sous 14** (J+21), et largement valide (J+400). La J+21 est celle qui
 * rend le réglage du seuil par établissement visible : elle change d'état selon qu'on règle 14 ou 30.
 *
 * ⚠ **Aucun badge n'est semé.** L'émission passe par `EmissionBadgeStaffHandler` — appairage,
 * génération de code, séquence de snapshot. La rejouer à la main ici dupliquerait un mécanisme dont ce
 * n'est pas le rôle, et le doublon divergerait le jour où le handler change.
 *
 * ── IDEMPOTENCE ─────────────────────────────────────────────────────────────────────────────────
 *
 * Chercher avant de créer, sur toute la classe (voir `FixturesIdempotentes`). ⚠ Ni `Employe`, ni
 * `Qualification`, ni `RattachementEmploye` ne portent de contrainte d'unicité en base : un second
 * chargement ne **crierait pas**, il **dupliquerait en silence**. La clé de recherche retenue est le
 * **matricule** pour l'employé — le seul identifiant métier qu'il porte — et le couple naturel pour
 * les autres.
 *
 * Les dates, elles, sont **recalculées à chaque chargement** : elles sont relatives à aujourd'hui, et
 * une démonstration dont toutes les échéances ont expiré ne démontre plus rien. Le nombre de lignes ne
 * bouge pas, ce qui est la définition de l'idempotence que `FixturesIdempotentesTest` vérifie.
 */
final class HrDemoFixtures extends Fixture implements DependentFixtureInterface
{
    public const MATRICULE_SITE_A = 'DEMO-RH-01';
    public const MATRICULE_SITE_B = 'DEMO-RH-02';
    public const MATRICULE_MULTI_SITE = 'DEMO-RH-03';
    public const MATRICULE_ORPHELIN = 'DEMO-RH-04';
    public const MATRICULE_SUSPENDU = 'DEMO-RH-05';
    public const MATRICULE_SORTI = 'DEMO-RH-06';

    /** Le poste du créneau semé — sert aussi de clé de recherche pour l'idempotence. */
    public const LIBELLE_POSTE_DEMO = 'Surveillance bassin (démo)';

    public function getDependencies(): array
    {
        return [PersonnelFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $siteA = $manager->getRepository(Etablissement::class)
            ->findOneBy(['nom' => PersonnelFixtures::ETAB_A_NOM]);
        $siteB = $manager->getRepository(Etablissement::class)
            ->findOneBy(['nom' => PersonnelFixtures::ETAB_B_NOM]);

        // ⚠ Une fixture dépendante peut être chargée seule (`--group`), et `getDependencies()` ne
        // garantit l'ordre que dans un chargement complet. Sans les établissements, on ne sème rien
        // plutôt que de semer des rattachements sans site — qui seraient invisibles et indélébiles.
        if (!$siteA instanceof Etablissement || !$siteB instanceof Etablissement) {
            return;
        }

        $today = new \DateTimeImmutable('today');

        $siteAEmployee = $this->employeeByMatricule(
            $manager,
            self::MATRICULE_SITE_A,
            'Renard',
            'Camille',
            'Maître-nageur sauveteur',
            TypeContrat::Cdi,
            $today->modify('-3 years'),
        );
        $siteBEmployee = $this->employeeByMatricule(
            $manager,
            self::MATRICULE_SITE_B,
            'Bonnet',
            'Sami',
            'Agent d\'entretien',
            TypeContrat::Cdi,
            $today->modify('-14 months'),
        );
        $multiSiteEmployee = $this->employeeByMatricule(
            $manager,
            self::MATRICULE_MULTI_SITE,
            'Alvarez',
            'Dominique',
            'Responsable de bassin',
            TypeContrat::Cdi,
            $today->modify('-6 years'),
        );

        // L'ORPHELIN — aucun rattachement, délibérément. Il n'est visible que d'un porteur de
        // `personnel.gerer_employe` sur l'établissement actif (`orphelinReserveAuxGestionnaires`), et
        // il ne peut recevoir aucun badge (RG-PERSO-09). C'est l'état dans lequel naît TOUT employé
        // créé par l'écran, puisque celui-ci n'envoie jamais de rattachement.
        $this->employeeByMatricule(
            $manager,
            self::MATRICULE_ORPHELIN,
            'Wei',
            'Alex',
            'Éducateur sportif',
            TypeContrat::Cdd,
            $today->modify('-2 weeks'),
        );

        $suspended = $this->employeeByMatricule(
            $manager,
            self::MATRICULE_SUSPENDU,
            'Marchand',
            'Yann',
            'Agent d\'accueil',
            TypeContrat::Saisonnier,
            $today->modify('-5 months'),
        );
        $suspended->setStatut(StatutEmploye::Suspendu);

        $gone = $this->employeeByMatricule(
            $manager,
            self::MATRICULE_SORTI,
            'Faure',
            'Inès',
            'Éducatrice sportive',
            TypeContrat::Cdd,
            $today->modify('-2 years'),
        );
        $gone->setStatut(StatutEmploye::Sorti);
        $gone->setDateSortie($today->modify('-1 month'));

        $this->attachment($manager, $siteAEmployee, $siteA, 'Bassin A', $today->modify('-3 years'));
        $this->attachment($manager, $siteBEmployee, $siteB, 'Vestiaires B', $today->modify('-14 months'));
        $this->attachment($manager, $multiSiteEmployee, $siteA, 'Bassin A', $today->modify('-6 years'));
        $this->attachment($manager, $multiSiteEmployee, $siteB, 'Bassin B', $today->modify('-2 years'));
        $this->attachment($manager, $suspended, $siteA, 'Accueil A', $today->modify('-5 months'));
        $this->attachment($manager, $gone, $siteA, 'Bassin A', $today->modify('-2 years'), $today->modify('-1 month'));

        // LES QUATRE ÉCHÉANCES. Chacune existe pour rendre un état visible sans saisie préalable.
        //
        //   J−30  expirée              → l'agent est planifié sur un créneau qu'il ne peut plus tenir
        //   J+7   expire sous 14 jours → alerte au seuil par défaut
        //   J+21  expire sous 30       → ⚠ change d'état selon le réglage : c'est LE témoin du seuil
        //   J+400 valide               → le cas tranquille, sans lequel « tout est rouge » ne se voit pas
        $expired = $this->qualification(
            $manager,
            $siteAEmployee,
            TypeQualification::Bnssa,
            $today->modify('-30 days'),
            $today->modify('-5 years'),
        );
        $this->qualification(
            $manager,
            $multiSiteEmployee,
            TypeQualification::Mns,
            $today->modify('+7 days'),
            $today->modify('-10 years'),
        );
        $this->qualification(
            $manager,
            $multiSiteEmployee,
            TypeQualification::Bnssa,
            $today->modify('+21 days'),
            $today->modify('-4 years'),
        );
        $this->qualification(
            $manager,
            $siteBEmployee,
            TypeQualification::Bafa,
            $today->modify('+400 days'),
            $today->modify('-2 years'),
        );

        // LE CRÉNEAU ET SON AFFECTATION — sans eux le roster n'a rien à montrer, et le badge rouge
        // « qualification manquante ou périmée » ne se démontre pas.
        //
        // ⚠ La qualification utilisée est celle qui a EXPIRÉ (J−30). C'est exactement le cas que
        // `CheckQualificationsCommand` décrit et que rien ne rattrape : l'affectation a été vérifiée
        // une seule fois, à sa création, et la validité a été raccourcie depuis. Le roster le signale ;
        // personne n'est prévenu.
        $shift = $this->shift($manager, $siteA, $today->modify('+1 day'));
        $this->shiftAssignment($manager, $shift, $siteAEmployee, $expired);

        $manager->flush();
    }

    /**
     * ⚠ `Employe` ne porte AUCUNE contrainte d'unicité en base : un second chargement ne lèverait pas,
     * il dupliquerait. Le `matricule` est le seul identifiant métier de l'entité — c'est donc lui la clé.
     */
    private function employeeByMatricule(
        ObjectManager $manager,
        string $matricule,
        string $lastName,
        string $firstName,
        string $jobTitle,
        TypeContrat $contract,
        \DateTimeImmutable $hiredOn,
    ): Employe {
        $existing = $manager->getRepository(Employe::class)->findOneBy(['matricule' => $matricule]);

        $employee = $existing instanceof Employe ? $existing : new Employe();
        $employee->setMatricule($matricule)
            ->setNom($lastName)
            ->setPrenom($firstName)
            ->setPoste($jobTitle)
            ->setTypeContrat($contract)
            ->setDateEntree($hiredOn);

        if (!$existing instanceof Employe) {
            $manager->persist($employee);
        }

        return $employee;
    }

    private function attachment(
        ObjectManager $manager,
        Employe $employee,
        Etablissement $establishment,
        string $localJobTitle,
        \DateTimeImmutable $from,
        ?\DateTimeImmutable $until = null,
    ): RattachementEmploye {
        $existing = $manager->getRepository(RattachementEmploye::class)
            ->findOneBy(['employe' => $employee, 'etablissement' => $establishment]);

        $attachment = $existing instanceof RattachementEmploye ? $existing : new RattachementEmploye();
        $attachment->setEmploye($employee)
            ->setEtablissement($establishment)
            ->setPosteLocal($localJobTitle)
            ->setDebut($from)
            ->setFin($until);

        if (!$existing instanceof RattachementEmploye) {
            $manager->persist($attachment);
        }

        return $attachment;
    }

    /**
     * Clé de recherche : le couple (employé, type). Un même agent peut détenir plusieurs brevets, mais
     * un seul de chaque type — deux BNSSA pour la même personne seraient une saisie en double, pas un
     * cas métier.
     */
    private function qualification(
        ObjectManager $manager,
        Employe $employee,
        TypeQualification $type,
        \DateTimeImmutable $validUntil,
        \DateTimeImmutable $obtainedOn,
    ): Qualification {
        $existing = $manager->getRepository(Qualification::class)
            ->findOneBy(['employe' => $employee, 'type' => $type]);

        $qualification = $existing instanceof Qualification ? $existing : new Qualification();
        $qualification->setEmploye($employee)
            ->setType($type)
            ->setDateObtention($obtainedOn)
            ->setDateValidite($validUntil);

        if (!$existing instanceof Qualification) {
            $manager->persist($qualification);
        }

        return $qualification;
    }

    private function shift(
        ObjectManager $manager,
        Etablissement $establishment,
        \DateTimeImmutable $day,
    ): CreneauTravail {
        $startsAt = $day->setTime(9, 0);

        $existing = $manager->getRepository(CreneauTravail::class)->findOneBy([
            'etablissement' => $establishment,
            'libellePoste' => self::LIBELLE_POSTE_DEMO,
        ]);

        $shift = $existing instanceof CreneauTravail ? $existing : new CreneauTravail();
        $shift->setEtablissement($establishment)
            ->setLibellePoste(self::LIBELLE_POSTE_DEMO)
            ->setDebut($startsAt)
            ->setFin($startsAt->setTime(17, 0))
            ->setQualificationRequise(TypeQualification::Bnssa)
            ->setEffectifRequis(2);

        if (!$existing instanceof CreneauTravail) {
            $manager->persist($shift);
        }

        return $shift;
    }

    /**
     * Une seule affectation pour un créneau qui en demande deux : le roster doit afficher
     * `sous_couvert`, l'état que sa table de couleurs ignorait jusqu'au 31/08 et rendait en gris neutre.
     */
    private function shiftAssignment(
        ObjectManager $manager,
        CreneauTravail $shift,
        Employe $employee,
        Qualification $used,
    ): AffectationTravail {
        $existing = $manager->getRepository(AffectationTravail::class)
            ->findOneBy(['creneauTravail' => $shift, 'employe' => $employee]);

        $assignment = $existing instanceof AffectationTravail ? $existing : new AffectationTravail();
        $assignment->setCreneauTravail($shift)
            ->setEmploye($employee)
            ->setQualificationUtilisee($used)
            ->setStatut(StatutAffectationTravail::Planifiee);

        if (!$existing instanceof AffectationTravail) {
            $manager->persist($assignment);
        }

        return $assignment;
    }
}
