<?php

declare(strict_types=1);

namespace App\Personnel\DataFixtures;

use App\Acces\Entity\Controleur;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Enum\ModeSeuil;
use App\Acces\Enum\SensEquipement;
use App\Acces\Enum\TypeEquipement;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données du module Personnel — autonome (ne dépend d'aucune fixture d'un autre module, même
 * patron que `App\Reporting\DataFixtures\L11Fixtures`) :
 *  - Groupe › Région › 2 Établissements (A, B).
 *  - Topologie Accès (Espace/EspaceAcces/Contrôleur/Équipement, établissement A) pour les tests de
 *    passage badge staff (CA-9, réutilise `SimulateurAccesAdapter`).
 *  - Permissions `personnel.*` + rôles RH/planning/lecture/employé + `acces.bloquer_support`
 *    (délégation ponctuelle agent d'accueil, §3 spec).
 *  - 5 utilisateurs : RH (A+B), planning (A), lecture (A), agent d'accueil (A), un compte lié à un
 *    Employé (« soi », A) pour `EmployeSoiVoter`.
 */
final class PersonnelFixtures extends Fixture
{
    public const GROUPE_NOM = 'Groupe Démo Personnel';
    public const REGION_NOM = 'Région Démo Personnel';
    public const ETAB_A_NOM = 'Site A Personnel';
    public const ETAB_B_NOM = 'Site B Personnel';

    public const MDP = 'aaa';
    public const EMAIL_RH = 'personnel.rh@itcotation.com';
    public const EMAIL_PLANNING = 'personnel.planning@itcotation.com';
    public const EMAIL_LECTURE = 'personnel.lecture@itcotation.com';
    public const EMAIL_ACCUEIL = 'personnel.accueil@itcotation.com';
    public const EMAIL_EMPLOYE_SOI = 'personnel.soi@itcotation.com';

    public const ESPACE_LIBELLE = 'Zone tourniquets Personnel A';
    public const CONTROLEUR_LIBELLE = 'Contrôleur Personnel A1';
    public const EQUIPEMENT_LIBELLE = 'Tourniquet Personnel Entrée A1';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $this->creerSequenceSnapshotSiManquante($manager);

        $groupe = (new Groupe())->setNom(self::GROUPE_NOM);
        $manager->persist($groupe);
        $region = (new Region())->setNom(self::REGION_NOM)->setGroupe($groupe);
        $manager->persist($region);

        $etabA = (new Etablissement())->setNom(self::ETAB_A_NOM)->setRegion($region)->setActif(true);
        $etabB = (new Etablissement())->setNom(self::ETAB_B_NOM)->setRegion($region)->setActif(true);
        $manager->persist($etabA);
        $manager->persist($etabB);

        // --- Topologie Accès (établissement A) : espace + contrôleur + tourniquet d'entrée ---
        $espaceSocle = (new Espace())->setNom('Zone Personnel A')->setEtablissement($etabA)->setType('zone');
        $manager->persist($espaceSocle);

        $espaceAcces = (new EspaceAcces())
            ->setLibelle(self::ESPACE_LIBELLE)
            ->setEspaceSocle($espaceSocle)
            ->setSeuilFmi(100)
            ->setModeSeuil(ModeSeuil::Blocage);
        $manager->persist($espaceAcces);

        $controleur = (new Controleur())
            ->setLibelle(self::CONTROLEUR_LIBELLE)
            ->setEspace($espaceAcces)
            ->setItboxRef('ITBOX-PERSONNEL-A1');
        $manager->persist($controleur);

        $equipement = (new Equipement())
            ->setLibelle(self::EQUIPEMENT_LIBELLE)
            ->setControleur($controleur)
            ->setType(TypeEquipement::Tourniquet)
            ->setSens(SensEquipement::Entree);
        $manager->persist($equipement);

        // --- Permissions personnel.* + acces.bloquer_support (délégation, §3 spec) ---
        $actions = ['gerer_employe', 'gerer_qualification', 'gerer_badge', 'gerer_planning', 'valider_absence', 'lire', 'lire_soi', 'declarer_absence_soi'];
        $perms = [];
        foreach ($actions as $action) {
            $perm = (new Permission())->setModule('personnel')->setAction($action);
            $manager->persist($perm);
            $perms[$action] = $perm;
        }
        $permBloquerSupport = $this->permissionAcces($manager, 'bloquer_support');
        // `acces.ingestion` : permet aux tests de passage badge staff (CA-9) de soumettre un
        // événement `POST /acces/passages` avec le compte RH, même moteur qu'un contrôleur réel
        // (RG-PERSO-07) — en production, ce compte technique serait dédié au contrôleur/ITBOX.
        $permIngestion = $this->permissionAcces($manager, 'ingestion');

        $roleRh = (new Role())->setNom('Personnel Administrateur RH');
        $roleRh->addPermission($perms['gerer_employe'])->addPermission($perms['gerer_qualification'])
            ->addPermission($perms['gerer_badge'])->addPermission($perms['lire'])
            ->addPermission($permIngestion);
        $manager->persist($roleRh);

        $rolePlanning = (new Role())->setNom('Personnel Responsable Planning');
        $rolePlanning->addPermission($perms['gerer_planning'])->addPermission($perms['valider_absence'])->addPermission($perms['lire']);
        $manager->persist($rolePlanning);

        $roleLecture = (new Role())->setNom('Personnel Lecture seule');
        $roleLecture->addPermission($perms['lire']);
        $manager->persist($roleLecture);

        $roleAccueil = (new Role())->setNom('Personnel Agent Accueil');
        $roleAccueil->addPermission($permBloquerSupport);
        $manager->persist($roleAccueil);

        $roleEmploye = (new Role())->setNom('Personnel Employé (soi)');
        $roleEmploye->addPermission($perms['lire_soi'])->addPermission($perms['declarer_absence_soi']);
        $manager->persist($roleEmploye);

        $utilisateurRh = $this->creerUtilisateur($manager, self::EMAIL_RH, 'Administrateur RH');
        $utilisateurPlanning = $this->creerUtilisateur($manager, self::EMAIL_PLANNING, 'Responsable Planning');
        $utilisateurLecture = $this->creerUtilisateur($manager, self::EMAIL_LECTURE, 'Lecteur Personnel');
        $utilisateurAccueil = $this->creerUtilisateur($manager, self::EMAIL_ACCUEIL, 'Agent Accueil');
        $utilisateurSoi = $this->creerUtilisateur($manager, self::EMAIL_EMPLOYE_SOI, 'Employé Soi-même');

        $manager->persist((new Affectation())->setUtilisateur($utilisateurRh)->setRole($roleRh)->setEtablissement($etabA));
        $manager->persist((new Affectation())->setUtilisateur($utilisateurRh)->setRole($roleRh)->setEtablissement($etabB));
        $manager->persist((new Affectation())->setUtilisateur($utilisateurPlanning)->setRole($rolePlanning)->setEtablissement($etabA));
        $manager->persist((new Affectation())->setUtilisateur($utilisateurPlanning)->setRole($rolePlanning)->setEtablissement($etabB));
        $manager->persist((new Affectation())->setUtilisateur($utilisateurLecture)->setRole($roleLecture)->setEtablissement($etabA));
        $manager->persist((new Affectation())->setUtilisateur($utilisateurAccueil)->setRole($roleAccueil)->setEtablissement($etabA));
        $manager->persist((new Affectation())->setUtilisateur($utilisateurSoi)->setRole($roleEmploye)->setEtablissement($etabA));

        $manager->flush();
    }

    /**
     * Séquence native MariaDB du curseur de version des supports (`App\Acces\Service\
     * VersionSnapshotSequencer`, consommée par `AppairageHandler::appairer()` — donc par
     * `EmissionBadgeStaffHandler`, RG-PERSO-06). Créée par la migration `Version20260817192240`, mais
     * `PersonnelApiTestCase` reconstruit le schéma via `SchemaTool` (métadonnées ORM), qui n'inclut pas
     * les objets créés hors mapping Doctrine par une migration. Cette fixture est volontairement
     * autonome (cf. docblock de classe) — sans rejeu de `AccesFixtures`, l'émission de badge staff
     * échouait en base isolée (« Unknown SEQUENCE: acces_snapshot_seq ») ; même patron défensif
     * qu'`App\Acces\DataFixtures\AccesFixtures::load()`.
     */
    private function creerSequenceSnapshotSiManquante(ObjectManager $manager): void
    {
        $connection = $manager->getConnection();
        $sequenceExiste = (bool) $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'acces_snapshot_seq'"
        );
        if (!$sequenceExiste) {
            $connection->executeStatement('CREATE SEQUENCE acces_snapshot_seq START WITH 1 INCREMENT BY 1');
        }
    }

    /**
     * Permission du module Accès dont cette fixture a besoin, mais qu'`AccesFixtures` crée aussi.
     *
     * Cette fixture est volontairement autonome (cf. docblock de classe) : elle doit savoir créer
     * la permission si elle tourne seule. Mais lors d'un `doctrine:fixtures:load` complet,
     * `AccesFixtures` s'exécute avant et l'a déjà insérée — la recréer violait la contrainte
     * `uniq_permission_module_action` et faisait échouer tout le chargement.
     */
    private function permissionAcces(ObjectManager $manager, string $action): Permission
    {
        $existante = $manager->getRepository(Permission::class)
            ->findOneBy(['module' => 'acces', 'action' => $action]);
        if ($existante instanceof Permission) {
            return $existante;
        }

        $permission = (new Permission())->setModule('acces')->setAction($action);
        $manager->persist($permission);

        return $permission;
    }

    private function creerUtilisateur(ObjectManager $manager, string $email, string $nom): Utilisateur
    {
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom($nom)->setActif(true);
        $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, self::MDP));
        $manager->persist($utilisateur);

        return $utilisateur;
    }
}
