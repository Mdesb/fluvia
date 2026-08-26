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

        $groupe = $this->parNom($manager, Groupe::class, self::GROUPE_NOM);

        $region = $this->parNom($manager, Region::class, self::REGION_NOM);
        $region->setGroupe($groupe);

        $etabA = $this->parNom($manager, Etablissement::class, self::ETAB_A_NOM);
        $etabA->setRegion($region)->setActif(true);

        $etabB = $this->parNom($manager, Etablissement::class, self::ETAB_B_NOM);
        $etabB->setRegion($region)->setActif(true);

        // --- Topologie Accès (établissement A) : espace + contrôleur + tourniquet d'entrée ---
        $espaceSocle = $this->parNom($manager, Espace::class, 'Zone Personnel A');
        $espaceSocle->setEtablissement($etabA)->setType('zone');

        $espaceAcces = $this->parLibelle($manager, EspaceAcces::class, self::ESPACE_LIBELLE);
        $espaceAcces->setEspaceSocle($espaceSocle)->setSeuilFmi(100)->setModeSeuil(ModeSeuil::Blocage);

        $controleur = $this->parLibelle($manager, Controleur::class, self::CONTROLEUR_LIBELLE);
        $controleur->setEspace($espaceAcces)->setItboxRef('ITBOX-PERSONNEL-A1');

        $equipement = $this->parLibelle($manager, Equipement::class, self::EQUIPEMENT_LIBELLE);
        $equipement->setControleur($controleur)
            ->setType(TypeEquipement::Tourniquet)
            ->setSens(SensEquipement::Entree);

        // --- Permissions personnel.* + acces.bloquer_support (délégation, §3 spec) ---
        $actions = ['gerer_employe', 'gerer_qualification', 'gerer_badge', 'gerer_planning', 'valider_absence', 'lire', 'lire_soi', 'declarer_absence_soi'];
        $perms = [];
        foreach ($actions as $action) {
            $perms[$action] = $this->permissionNommee($manager, 'personnel', $action);
        }
        $permBloquerSupport = $this->permissionAcces($manager, 'bloquer_support');
        // `acces.ingestion` : permet aux tests de passage badge staff (CA-9) de soumettre un
        // événement `POST /acces/passages` avec le compte RH, même moteur qu'un contrôleur réel
        // (RG-PERSO-07) — en production, ce compte technique serait dédié au contrôleur/ITBOX.
        $permIngestion = $this->permissionAcces($manager, 'ingestion');

        $roleRh = $this->roleNomme($manager, 'Personnel Administrateur RH');
        $roleRh->addPermission($perms['gerer_employe'])->addPermission($perms['gerer_qualification'])
            ->addPermission($perms['gerer_badge'])->addPermission($perms['lire'])
            ->addPermission($permIngestion);
        $manager->persist($roleRh);

        $rolePlanning = $this->roleNomme($manager, 'Personnel Responsable Planning');
        $rolePlanning->addPermission($perms['gerer_planning'])->addPermission($perms['valider_absence'])->addPermission($perms['lire']);
        $manager->persist($rolePlanning);

        $roleLecture = $this->roleNomme($manager, 'Personnel Lecture seule');
        $roleLecture->addPermission($perms['lire']);
        $manager->persist($roleLecture);

        $roleAccueil = $this->roleNomme($manager, 'Personnel Agent Accueil');
        $roleAccueil->addPermission($permBloquerSupport);
        $manager->persist($roleAccueil);

        $roleEmploye = $this->roleNomme($manager, 'Personnel Employé (soi)');
        $roleEmploye->addPermission($perms['lire_soi'])->addPermission($perms['declarer_absence_soi']);
        $manager->persist($roleEmploye);

        $utilisateurRh = $this->creerUtilisateur($manager, self::EMAIL_RH, 'Administrateur RH');
        $utilisateurPlanning = $this->creerUtilisateur($manager, self::EMAIL_PLANNING, 'Responsable Planning');
        $utilisateurLecture = $this->creerUtilisateur($manager, self::EMAIL_LECTURE, 'Lecteur Personnel');
        $utilisateurAccueil = $this->creerUtilisateur($manager, self::EMAIL_ACCUEIL, 'Agent Accueil');
        $utilisateurSoi = $this->creerUtilisateur($manager, self::EMAIL_EMPLOYE_SOI, 'Employé Soi-même');

        $this->affectationUnique($manager, $utilisateurRh, $roleRh, $etabA);
        $this->affectationUnique($manager, $utilisateurRh, $roleRh, $etabB);
        $this->affectationUnique($manager, $utilisateurPlanning, $rolePlanning, $etabA);
        $this->affectationUnique($manager, $utilisateurPlanning, $rolePlanning, $etabB);
        $this->affectationUnique($manager, $utilisateurLecture, $roleLecture, $etabA);
        $this->affectationUnique($manager, $utilisateurAccueil, $roleAccueil, $etabA);
        $this->affectationUnique($manager, $utilisateurSoi, $roleEmploye, $etabA);

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
        $existant = $manager->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);

        if ($existant instanceof Utilisateur) {
            return $existant->setNom($nom)->setActif(true);
        }

        // Le mot de passe n'est posé qu'à la création : le rejouer écraserait un mot de passe changé
        // depuis, et réécrirait un hachage pour rien à chaque chargement.
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom($nom)->setActif(true);
        $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, self::MDP));
        $manager->persist($utilisateur);

        return $utilisateur;
    }

    /**
     * Un rôle existant plutôt qu'un doublon.
     *
     * `Role.nom` porte une unicité **globale** : recharger les fixtures sur une base qui les a déjà
     * échoue sur « Duplicate entry ». Ce n'est pas théorique — c'est exactement ce qui m'a empêché de
     * régénérer les données de démonstration de la préproduction le 24/08, et qui a fini par me faire
     * effacer les rattachements de droits de trente-quatre rôles.
     *
     * Le harnais de test ne voit jamais ce cas : il recrée le schéma depuis les entités à chaque classe
     * de test, donc les fixtures partent toujours d'une base vide. Les deux mondes ne se croisent pas.
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

    /**
     * **Les sept familles qui manquaient, trouvées en inventoriant et non en suivant les échecs.**
     *
     * Ce fichier avait servi de patron pour `roleNomme()` — et ne gardait que ses **rôles**. Le
     * rechargement complet a buté dessus sur `personnel-gerer_employe`, signalé par `claude-G`, qui a
     * ajouté : *« la fixture modèle a été corrigée sur la famille qui criait, et l'inventaire n'a pas
     * été fait sur les six autres. »*
     *
     * Onze types construits ici. Quatre étaient gardés. Les sept autres sont traités d'un coup, parce
     * que corriger celui qui bloque fait avancer le curseur d'un cran sans rendre la fixture idempotente
     * — c'est la troisième fois de la journée que ce motif se vérifie (D52).
     *
     * **Et quatre d'entre eux ne criaient pas** : `Groupe`, `Region`, `Etablissement`, plus toute la
     * topologie d'accès. Aucune contrainte d'unicité métier, donc aucune erreur au second chargement —
     * juste un second « Site A Personnel » et un second tourniquet. `Etablissement` est le pire : c'est
     * la frontière de cloisonnement à laquelle tout est rattaché.
     *
     * @template T of object
     * @param class-string<T> $classe
     * @return T
     */
    private function parNom(ObjectManager $manager, string $classe, string $nom): object
    {
        $existant = $manager->getRepository($classe)->findOneBy(['nom' => $nom]);

        if ($existant !== null) {
            return $existant;
        }

        $entite = new $classe();
        $entite->setNom($nom);
        $manager->persist($entite);

        return $entite;
    }

    /**
     * Même chose pour la topologie d'accès, qui s'identifie par un libellé et non par un nom.
     *
     * @template T of object
     * @param class-string<T> $classe
     * @return T
     */
    private function parLibelle(ObjectManager $manager, string $classe, string $libelle): object
    {
        $existant = $manager->getRepository($classe)->findOneBy(['libelle' => $libelle]);

        if ($existant !== null) {
            return $existant;
        }

        $entite = new $classe();
        $entite->setLibelle($libelle);
        $manager->persist($entite);

        return $entite;
    }

    private function permissionNommee(ObjectManager $manager, string $module, string $action): Permission
    {
        $existante = $manager->getRepository(Permission::class)
            ->findOneBy(['module' => $module, 'action' => $action]);

        if ($existante instanceof Permission) {
            return $existante;
        }

        $permission = (new Permission())->setModule($module)->setAction($action);
        $manager->persist($permission);

        return $permission;
    }

    /**
     * **`Affectation` ne porte aucune unicité en base.** Un second chargement n'échoue donc pas : il
     * empile des doublons, silencieusement. Et les droits effectifs d'un utilisateur se calculent en
     * parcourant ses affectations — un doublon n'est pas cosmétique, c'est un calcul de droits sur des
     * données fausses. Trouvé par `claude-G`.
     */
    private function affectationUnique(
        ObjectManager $manager,
        Utilisateur $utilisateur,
        Role $role,
        Etablissement $etablissement,
    ): void {
        $existante = $manager->getRepository(Affectation::class)->findOneBy([
            'utilisateur' => $utilisateur,
            'role' => $role,
            'etablissement' => $etablissement,
        ]);

        if ($existante instanceof Affectation) {
            return;
        }

        $manager->persist(
            (new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etablissement)
        );
    }
}
