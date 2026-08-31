<?php

declare(strict_types=1);

namespace App\Support\DataFixtures;

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
 * Jeu de données du module `App\Support` — autonome (même patron que
 * `App\Personnel\DataFixtures\PersonnelFixtures`, ne dépend d'aucune fixture d'un autre module) :
 *  - Groupe › Région › 2 Établissements (A, B).
 *  - Permissions `support.*` + 9 rôles couvrant tous les acteurs de la spec (§3) : rédacteur KB
 *    global, rédacteur KB local (A et B, pour le cloisonnement), agent (lecture KB), exploitant
 *    (ouverture de ticket, A et B), responsable d'établissement (lire_ticket_etablissement), agent
 *    support N1/N2, administrateur support.
 */
final class SupportFixtures extends Fixture
{
    use FixturesIdempotentes;

    public const GROUPE_NOM = 'Groupe Démo Support';
    public const REGION_NOM = 'Région Démo Support';
    public const ETAB_A_NOM = 'Site A Support';
    public const ETAB_B_NOM = 'Site B Support';

    public const MDP = 'SupportDemo#2026';
    public const EMAIL_REDACTEUR_GLOBAL = 'support.redacteur.global@itcotation.com';
    public const EMAIL_REDACTEUR_LOCAL_A = 'support.redacteur.a@itcotation.com';
    public const EMAIL_REDACTEUR_LOCAL_B = 'support.redacteur.b@itcotation.com';
    public const EMAIL_AGENT_LECTURE = 'support.agent.lecture@itcotation.com';
    public const EMAIL_EXPLOITANT_A = 'support.exploitant.a@itcotation.com';
    public const EMAIL_EXPLOITANT_B = 'support.exploitant.b@itcotation.com';
    public const EMAIL_RESPONSABLE_ETAB_A = 'support.responsable.a@itcotation.com';
    public const EMAIL_AGENT_N1 = 'support.agent.n1@itcotation.com';
    public const EMAIL_AGENT_N2 = 'support.agent.n2@itcotation.com';
    public const EMAIL_ADMIN = 'support.admin@itcotation.com';
    /**
     * LE COMPTE QUE PERSONNE N'A CONFIGURE POUR L'ASSISTANCE — et c'est tout son interet.
     *
     * Son role ne porte que `caisse.lire`. Il ne peut donc rien demander au module d'assistance
     * par ses roles ; ce qu'il peut y faire, il le tient du socle et de rien d'autre. Un test
     * ecrit sur l'un des neuf autres comptes serait vert meme si le socle etait retire.
     */
    public const EMAIL_SANS_ROLE_SUPPORT = 'support.compte.ordinaire@itcotation.com';

    /** @var list<string> */
    public const ACTIONS = [
        'lire', 'gerer_kb_globale', 'gerer_categorie', 'gerer_kb_locale', 'ouvrir_ticket',
        'lire_ticket_soi', 'lire_ticket_etablissement', 'traiter_ticket_n1', 'traiter_ticket_n2', 'administrer',
    ];

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $groupe = $this->parNom($manager, Groupe::class, self::GROUPE_NOM);

        $region = $this->parNom($manager, Region::class, self::REGION_NOM);
        $region->setGroupe($groupe);

        $etabA = $this->parNom($manager, Etablissement::class, self::ETAB_A_NOM);
        $etabA->setRegion($region)->setActif(true);

        $etabB = $this->parNom($manager, Etablissement::class, self::ETAB_B_NOM);
        $etabB->setRegion($region)->setActif(true);

        // Idempotence (ordre A 26/08) : `Permission(module, action)` et `Role.nom` portent une unicité
        // globale. Un rechargement sur une base qui les a déjà — la régénération des données de démo de
        // la préprod — échouait sur « Duplicate entry ». On cherche avant de créer. `addPermission` est
        // gardé par `contains`, donc réattacher une permission à un rôle réutilisé est sans effet.
        $perms = [];
        foreach (self::ACTIONS as $action) {
            $perms[$action] = $this->permissionSupport($manager, $action);
        }

        $roleRedacteurGlobal = $this->roleNomme($manager, 'Support Rédacteur KB Global');
        $roleRedacteurGlobal->addPermission($perms['gerer_kb_globale'])->addPermission($perms['gerer_categorie'])->addPermission($perms['lire']);

        $roleRedacteurLocal = $this->roleNomme($manager, 'Support Rédacteur KB Local');
        $roleRedacteurLocal->addPermission($perms['gerer_kb_locale'])->addPermission($perms['lire']);

        $roleAgentLecture = $this->roleNomme($manager, 'Support Agent (lecture KB)');
        $roleAgentLecture->addPermission($perms['lire']);

        $roleExploitant = $this->roleNomme($manager, 'Support Exploitant (ticket)');
        $roleExploitant->addPermission($perms['ouvrir_ticket'])->addPermission($perms['lire_ticket_soi']);

        $roleResponsableEtab = $this->roleNomme($manager, 'Support Responsable Établissement');
        $roleResponsableEtab->addPermission($perms['lire_ticket_etablissement']);

        $roleAgentN1 = $this->roleNomme($manager, 'Support Agent N1');
        $roleAgentN1->addPermission($perms['traiter_ticket_n1'])->addPermission($perms['lire']);

        $roleAgentN2 = $this->roleNomme($manager, 'Support Agent N2');
        $roleAgentN2->addPermission($perms['traiter_ticket_n2'])->addPermission($perms['lire']);

        $roleAdmin = $this->roleNomme($manager, 'Support Administrateur');
        $roleAdmin->addPermission($perms['administrer'])->addPermission($perms['lire']);

        // Un role de terrain, sans le moindre droit d'assistance. `permissionNommee` vient du trait
        // et cherche avant de creer : pas de doublon si un autre jeu de donnees a deja pose
        // `caisse.lire`.
        $roleSansAssistance = $this->roleNomme($manager, 'Caisse (sans droit d’assistance)');
        $roleSansAssistance->addPermission($this->permissionNommee($manager, 'caisse', 'lire'));

        $redacteurGlobal = $this->creerUtilisateur($manager, self::EMAIL_REDACTEUR_GLOBAL, 'Rédacteur KB Global');
        $redacteurLocalA = $this->creerUtilisateur($manager, self::EMAIL_REDACTEUR_LOCAL_A, 'Rédacteur KB Local A');
        $redacteurLocalB = $this->creerUtilisateur($manager, self::EMAIL_REDACTEUR_LOCAL_B, 'Rédacteur KB Local B');
        $agentLecture = $this->creerUtilisateur($manager, self::EMAIL_AGENT_LECTURE, 'Agent Lecture KB');
        $exploitantA = $this->creerUtilisateur($manager, self::EMAIL_EXPLOITANT_A, 'Exploitant A');
        $exploitantB = $this->creerUtilisateur($manager, self::EMAIL_EXPLOITANT_B, 'Exploitant B');
        $responsableA = $this->creerUtilisateur($manager, self::EMAIL_RESPONSABLE_ETAB_A, 'Responsable Établissement A');
        $agentN1 = $this->creerUtilisateur($manager, self::EMAIL_AGENT_N1, 'Agent Support N1');
        $agentN2 = $this->creerUtilisateur($manager, self::EMAIL_AGENT_N2, 'Agent Support N2');
        $admin = $this->creerUtilisateur($manager, self::EMAIL_ADMIN, 'Administrateur Support');
        $sansRoleSupport = $this->creerUtilisateur($manager, self::EMAIL_SANS_ROLE_SUPPORT, 'Compte ordinaire');

        $this->affectationUnique($manager, $redacteurGlobal, $roleRedacteurGlobal, $etabA);
        $this->affectationUnique($manager, $redacteurGlobal, $roleRedacteurGlobal, $etabB);
        $this->affectationUnique($manager, $redacteurLocalA, $roleRedacteurLocal, $etabA);
        $this->affectationUnique($manager, $redacteurLocalB, $roleRedacteurLocal, $etabB);
        $this->affectationUnique($manager, $agentLecture, $roleAgentLecture, $etabA);
        $this->affectationUnique($manager, $exploitantA, $roleExploitant, $etabA);
        $this->affectationUnique($manager, $exploitantB, $roleExploitant, $etabB);
        $this->affectationUnique($manager, $responsableA, $roleResponsableEtab, $etabA);
        $this->affectationUnique($manager, $agentN1, $roleAgentN1, $etabA);
        $this->affectationUnique($manager, $agentN1, $roleAgentN1, $etabB);
        $this->affectationUnique($manager, $agentN2, $roleAgentN2, $etabA);
        $this->affectationUnique($manager, $agentN2, $roleAgentN2, $etabB);
        $this->affectationUnique($manager, $admin, $roleAdmin, $etabA);
        $this->affectationUnique($manager, $admin, $roleAdmin, $etabB);
        $this->affectationUnique($manager, $sansRoleSupport, $roleSansAssistance, $etabA);

        $manager->flush();
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

    /** Le couple `(module, action)` est unique — rendre l'existant plutôt qu'un doublon (ordre A 26/08). */
    private function permissionSupport(ObjectManager $manager, string $action): Permission
    {
        $existante = $manager->getRepository(Permission::class)
            ->findOneBy(['module' => 'support', 'action' => $action]);
        if ($existante instanceof Permission) {
            return $existante;
        }

        $permission = $this->permissionNommee($manager, 'support', $action);
        $manager->persist($permission);

        return $permission;
    }

    /** `Role.nom` est unique — rendre l'existant plutôt qu'un doublon (ordre A 26/08). */
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
     * **Les trois familles qui ne crient pas.**
     *
     * `claude-B` avait gardé les permissions et les rôles — les deux qui produisent un
     * « Duplicate entry ». Le double chargement a donc avancé d'un cran et buté sur les utilisateurs,
     * puis aurait buté ici sans erreur du tout.
     *
     * `Groupe`, `Region` et `Etablissement` ne portent d'unicité que sur leur identifiant technique,
     * régénéré à chaque construction. Un second chargement ne lève rien : il crée un second
     * « Groupe Support », une seconde région, **deux établissements A**. Silencieusement.
     *
     * Et l'établissement est le pire des trois : c'est **la frontière de cloisonnement** à laquelle
     * tout est rattaché. Deux établissements du même nom, et la question « cet utilisateur a-t-il le
     * droit ? » a deux réponses selon la ligne qu'on lit.
     *
     * C'est le motif D52, vérifié pour la sixième fois de la journée : **il faut inventorier ce qu'une
     * fixture construit, pas suivre ce qui casse.** Suivre les erreurs ne corrige que les familles
     * assez contraintes pour en produire une.
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
     * `Affectation` ne porte **aucune** unicité en base : un rechargement empile des doublons sans
     * lever. Et les droits effectifs d'un utilisateur se calculent en parcourant ces lignes — un
     * doublon n'est pas cosmétique, c'est un calcul de droits sur des données fausses.
     *
     * Quatorze affectations dans ce fichier, c'est le plus gros gisement du dépôt. Trouvé par
     * `claude-G` sur ses propres fixtures.
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
