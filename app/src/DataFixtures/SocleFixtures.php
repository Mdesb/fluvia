<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
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
 * Jeu de données minimal du socle (US-L0-*) : 1 groupe / 1 région / 2 établissements,
 * un administrateur (droits complets sur A et B), un lecteur (lecture seule sur A uniquement).
 */
final class SocleFixtures extends Fixture
{
    use FixturesIdempotentes;

    // Valeurs déterministes exploitées par les tests.
    public const ETAB_A_NOM = 'Piscine A';
    public const ETAB_B_NOM = 'Patinoire B';

    /**
     * L'ÉTABLISSEMENT ÉDITEUR — celui qui exploite la plateforme, désigné par `EDITOR_TENANT_ID`.
     *
     * Il est ici pour que les écrans de l'application éditeur puissent être OUVERTS, pas seulement
     * construits. Rien dans le modèle ne le distingue d'un client, et c'est voulu : `Etablissement`
     * n'a pas de type, la désignation appartient au déploiement (`EditorTenantResolver`).
     *
     * ⚠ Son identifiant est engendré à la création, comme celui de tout établissement — il n'y a
     * pas de setter, et il ne doit pas y en avoir sur l'entité pivot du cloisonnement. Un test qui
     * a besoin que l'éditeur soit DÉSIGNÉ le cherche donc par ce nom et pose lui-même
     * `EDITOR_TENANT_ID` avant d'ouvrir un client.
     */
    public const ETAB_EDITEUR_NOM = 'IT Cotation (éditeur)';
    public const ADMIN_EMAIL = 'admin@itcotation.com';
    public const ADMIN_MDP = 'aaa';
    public const LECTEUR_EMAIL = 'lecteur@itcotation.com';
    public const LECTEUR_MDP = 'aaa';

    /**
     * Un employé de l'éditeur qui n'a QUE l'assistance — pas la facturation.
     *
     * Il existe pour qu'on puisse vérifier ce que « l'argent est un rôle à part » veut dire :
     * il lit la fiche d'un client, il ne lit pas ce que ce client paie.
     */
    public const ASSISTANCE_EDITEUR_EMAIL = 'assistance@itcotation.com';
    public const ASSISTANCE_EDITEUR_MDP = 'aaa';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $groupe = $this->parNom($manager, Groupe::class, 'Groupe Loisirs Métropole');

        $region = $this->parNom($manager, Region::class, 'Région Est');
        $region->setGroupe($groupe);

        $etabA = $this->parNom($manager, Etablissement::class, self::ETAB_A_NOM);
        $etabA->setRegion($region)->setActif(true);

        $etabB = $this->parNom($manager, Etablissement::class, self::ETAB_B_NOM);
        $etabB->setRegion($region)->setActif(true);

        // L'éditeur est un établissement comme un autre — même groupe, même région, même chaîne
        // `Groupe → Région → Établissement` par laquelle le fichier client est cloisonné. Ce qui
        // le distingue n'est pas dans le modèle : c'est `EDITOR_TENANT_ID` qui le désigne.
        $etabEditeur = $this->parNom($manager, Etablissement::class, self::ETAB_EDITEUR_NOM);
        $etabEditeur->setRegion($region)->setActif(true);

        // Permissions (RG-SOCLE-02).
        $permOrgGerer = $this->permissionNommee($manager, 'organisation', 'gerer');
        $permSecGerer = $this->permissionNommee($manager, 'securite', 'gerer');
        $permLireTout = $this->permissionNommee($manager, '*', 'lire');
        $manager->persist($permOrgGerer);
        $manager->persist($permSecGerer);
        $manager->persist($permLireTout);

        // Rôles (RG-SOCLE-03).
        $roleAdmin = $this->roleNomme($manager, 'Administrateur groupe');
        $roleAdmin->addPermission($permOrgGerer)->addPermission($permSecGerer)->addPermission($permLireTout);
        $manager->persist($roleAdmin);

        $roleLecteur = $this->roleNomme($manager, 'Lecture seule');
        $roleLecteur->addPermission($permLireTout);
        $manager->persist($roleLecteur);

        // ── LES DROITS DE L'ÉDITEUR ────────────────────────────────────────────────────────────
        //
        // Les sept ressources `Editor*` n'étaient gardées que par « être connecté ». Le seul autre
        // rempart était l'établissement actif : un employé rattaché à l'éditeur voyait donc TOUT,
        // y compris la facturation des abonnements et les créances.
        //
        // Cinq permissions, quatre rôles, nommés par la conséquence. Peu, délibérément : Maxime
        // dit que 57 rôles × 200 permissions sont illisibles, et lui en refaire la moitié serait
        // répondre à sa plainte par la même chose en plus petit.
        $permLireClient = $this->permissionNommee($manager, 'editor', 'read_customer');
        $permLireAbo = $this->permissionNommee($manager, 'editor', 'read_subscription');
        $permGererOffre = $this->permissionNommee($manager, 'editor', 'manage_offer');
        $permLireFactu = $this->permissionNommee($manager, 'editor', 'read_billing');
        $permAccesSupport = $this->permissionNommee($manager, 'editor', 'support_access');
        foreach ([$permLireClient, $permLireAbo, $permGererOffre, $permLireFactu, $permAccesSupport] as $perm) {
            $manager->persist($perm);
        }

        // ⚠ L'ARGENT EST UN RÔLE À PART, et c'est le seul découpage qui permet de recruter un agent
        // d'assistance sans lui montrer ce que gagne l'entreprise. `editor.read_billing` n'est
        // porté que par la direction et l'administratif.
        $roleEditeurDirection = $this->roleNomme($manager, 'Éditeur — Direction');
        foreach ([$permLireClient, $permLireAbo, $permGererOffre, $permLireFactu, $permAccesSupport] as $perm) {
            $roleEditeurDirection->addPermission($perm);
        }
        $manager->persist($roleEditeurDirection);

        // L'assistance lit la fiche client, mais la fiche se TAIT sur les montants et le mandat
        // sans `editor.read_billing` : la permission ne suffirait pas, c'est la réponse qui est
        // réduite (voir `EditorCustomersProvider`).
        $roleEditeurAssistance = $this->roleNomme($manager, 'Éditeur — Assistance');
        $roleEditeurAssistance->addPermission($permLireClient)->addPermission($permAccesSupport);
        $manager->persist($roleEditeurAssistance);

        $roleEditeurAdmin = $this->roleNomme($manager, 'Éditeur — Administratif');
        $roleEditeurAdmin->addPermission($permLireClient)->addPermission($permLireAbo)->addPermission($permLireFactu);
        $manager->persist($roleEditeurAdmin);

        // L'administrateur de groupe administre aussi ce qui appartient à l'éditeur. Son joker
        // `*.lire` ne suffit pas : `manage_offer` et `support_access` ne sont pas des lectures, et
        // `read_customer` ne s'écrit pas `lire`. On les lui donne explicitement plutôt que de
        // renommer les permissions pour les faire entrer dans le joker — un droit accordé par
        // coïncidence de vocabulaire est un droit que personne n'a décidé.
        foreach ([$permLireClient, $permLireAbo, $permGererOffre, $permLireFactu, $permAccesSupport] as $perm) {
            $roleAdmin->addPermission($perm);
        }

        $roleEditeurCommerce = $this->roleNomme($manager, 'Éditeur — Commerce');
        $roleEditeurCommerce->addPermission($permLireClient)->addPermission($permLireAbo)->addPermission($permGererOffre);
        $manager->persist($roleEditeurCommerce);

        // Utilisateurs (RG-SOCLE-06 : mot de passe haché).
        $admin = $this->utilisateurParEmail($manager, self::ADMIN_EMAIL, self::ADMIN_MDP);
        $admin->setNom('Administratrice Socle')
            ->setActif(true)
            ->setRolesSecurite(['ROLE_ADMIN']);

        $lecteur = $this->utilisateurParEmail($manager, self::LECTEUR_EMAIL, self::LECTEUR_MDP);
        $lecteur->setNom('Lecteur Socle')->setActif(true);

        $assistanceEditeur = $this->utilisateurParEmail($manager, self::ASSISTANCE_EDITEUR_EMAIL, self::ASSISTANCE_EDITEUR_MDP);
        $assistanceEditeur->setNom('Agent Assistance Éditeur')->setActif(true);

        // Affectations (RG-SOCLE-03/05) : admin sur A et B, lecteur sur A seulement.
        $this->affectationUnique($manager, $admin, $roleAdmin, $etabA);
        $this->affectationUnique($manager, $admin, $roleAdmin, $etabB);
        $this->affectationUnique($manager, $lecteur, $roleLecteur, $etabA);

        // L'admin est affecté à l'éditeur, le lecteur NON — et cette asymétrie est utile : elle
        // permet de vérifier qu'un compte sans affectation ne voit pas l'établissement éditeur du
        // tout, puisque la liste des établissements est jointe aux affectations.
        // Sur l'éditeur, l'admin porte le rôle de DIRECTION et non « Administrateur groupe » :
        // le joker `*.lire` de ce dernier ne couvre ni `editor.manage_offer` ni
        // `editor.support_access`, qui ne sont pas des lectures.
        $this->affectationUnique($manager, $admin, $roleEditeurDirection, $etabEditeur);

        // ⚠ SUR « Piscine A », ET CE N'EST PAS UNE ERREUR. Les tests d'intégration du module
        // désignent A comme éditeur (`EDITOR_TENANT_ID`), parce que la désignation appartient au
        // déploiement et qu'un test est un déploiement comme un autre. L'agent d'assistance doit
        // donc être affecté là où ces tests placent l'éditeur.
        $this->affectationUnique($manager, $assistanceEditeur, $roleEditeurAssistance, $etabA);

        $manager->flush();
    }

    /**
     * Les fixtures se rechargent : chaque création cherche d'abord.
     *
     * **Le 24/08, régénérer les données de démonstration de la préproduction a échoué en cours de
     * route, après avoir tronqué la table des rattachements droits-rôles.** Trente-quatre rôles se sont
     * retrouvés à zéro droit, et Maxime n'a plus pu tester qu'avec son propre compte. La cause n'était
     * pas l'incident : **un chargement complet n'avait jamais fonctionné**, parce que quatorze fixtures
     * créaient aveuglément des objets à contrainte d'unicité.
     *
     * **Le harnais ne pouvait pas le voir** : il recrée le schéma depuis les entités à chaque classe de
     * test, donc les fixtures partent toujours d'une base vide, et elles sont chargées sélectivement.
     * Le seul geste qui révèle le défaut — charger deux fois — n'était fait nulle part.
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
     * **Le cas le plus vicieux, trouvé par `claude-G`, et le seul qui ne casse pas.**
     *
     * `Affectation` ne porte **aucune contrainte d'unicité en base**. Un second chargement n'échoue
     * donc pas : il **empile des doublons**, silencieusement. Et les droits effectifs d'un utilisateur
     * se calculent en parcourant ses affectations — une affectation en double n'est pas un doublon
     * cosmétique, c'est un calcul de droits qui repose sur des données fausses.
     *
     * C'est exactement le genre de défaut qu'on ne trouve qu'en cherchant autre chose.
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

    /**
     * **La quatrième famille, et j'avais laissé passer les trois autres avant elle.**
     *
     * `claude-G` m'avait prévenu qu'il y avait quatre familles d'entités uniques dans ce fichier, pas
     * une. J'en ai gardé trois — rôles, permissions, affectations — et j'ai déclaré la fixture
     * idempotente. Le double chargement a avancé d'un cran et buté sur
     * `Duplicate entry 'admin@itcotation.com'`.
     *
     * C'est exactement ce qu'elle avait annoncé : **corriger la seule famille qui bloque fait avancer
     * le curseur sans rendre la fixture idempotente.** Et je l'ai fait après l'avoir lu.
     *
     * Le mot de passe n'est posé qu'à la création : le rejouer à chaque chargement réécrirait un hachage
     * pour rien, et surtout écraserait un mot de passe changé depuis.
     */
    private function utilisateurParEmail(ObjectManager $manager, string $email, string $motDePasse): Utilisateur
    {
        $existant = $manager->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);

        if ($existant instanceof Utilisateur) {
            return $existant;
        }

        $utilisateur = (new Utilisateur())->setEmail($email);
        $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, $motDePasse));
        $manager->persist($utilisateur);

        return $utilisateur;
    }

    /**
     * **Le cas qui ne crie pas, et c'est pour ça qu'il est le plus dangereux.**
     *
     * `Groupe`, `Region` et `Etablissement` ne portent d'unicité que sur leur identifiant technique,
     * régénéré à chaque construction. Un second chargement ne lève donc **aucune** erreur : il crée un
     * second « Groupe Loisirs Métropole », une seconde « Région Est », deux piscines A. Silencieusement.
     *
     * On ne l'aurait pas su en corrigeant les erreurs une par une, parce qu'il n'en produit pas. Il
     * apparaît quand on **compte les lignes** — le contrôle que `claude-D` a exigé d'ajouter au test
     * après avoir remarqué que « ne lève pas » n'est pas « idempotent ».
     *
     * Un établissement en double n'est pas cosmétique : c'est la frontière sur laquelle repose tout le
     * cloisonnement. Deux établissements du même nom, et l'on ne sait plus lequel porte les droits.
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
}
