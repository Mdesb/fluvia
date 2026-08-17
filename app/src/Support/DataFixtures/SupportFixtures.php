<?php

declare(strict_types=1);

namespace App\Support\DataFixtures;

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
        $groupe = (new Groupe())->setNom(self::GROUPE_NOM);
        $manager->persist($groupe);
        $region = (new Region())->setNom(self::REGION_NOM)->setGroupe($groupe);
        $manager->persist($region);

        $etabA = (new Etablissement())->setNom(self::ETAB_A_NOM)->setRegion($region)->setActif(true);
        $etabB = (new Etablissement())->setNom(self::ETAB_B_NOM)->setRegion($region)->setActif(true);
        $manager->persist($etabA);
        $manager->persist($etabB);

        $perms = [];
        foreach (self::ACTIONS as $action) {
            $perm = (new Permission())->setModule('support')->setAction($action);
            $manager->persist($perm);
            $perms[$action] = $perm;
        }

        $roleRedacteurGlobal = (new Role())->setNom('Support Rédacteur KB Global');
        $roleRedacteurGlobal->addPermission($perms['gerer_kb_globale'])->addPermission($perms['gerer_categorie'])->addPermission($perms['lire']);
        $manager->persist($roleRedacteurGlobal);

        $roleRedacteurLocal = (new Role())->setNom('Support Rédacteur KB Local');
        $roleRedacteurLocal->addPermission($perms['gerer_kb_locale'])->addPermission($perms['lire']);
        $manager->persist($roleRedacteurLocal);

        $roleAgentLecture = (new Role())->setNom('Support Agent (lecture KB)');
        $roleAgentLecture->addPermission($perms['lire']);
        $manager->persist($roleAgentLecture);

        $roleExploitant = (new Role())->setNom('Support Exploitant (ticket)');
        $roleExploitant->addPermission($perms['ouvrir_ticket'])->addPermission($perms['lire_ticket_soi']);
        $manager->persist($roleExploitant);

        $roleResponsableEtab = (new Role())->setNom('Support Responsable Établissement');
        $roleResponsableEtab->addPermission($perms['lire_ticket_etablissement']);
        $manager->persist($roleResponsableEtab);

        $roleAgentN1 = (new Role())->setNom('Support Agent N1');
        $roleAgentN1->addPermission($perms['traiter_ticket_n1'])->addPermission($perms['lire']);
        $manager->persist($roleAgentN1);

        $roleAgentN2 = (new Role())->setNom('Support Agent N2');
        $roleAgentN2->addPermission($perms['traiter_ticket_n2'])->addPermission($perms['lire']);
        $manager->persist($roleAgentN2);

        $roleAdmin = (new Role())->setNom('Support Administrateur');
        $roleAdmin->addPermission($perms['administrer'])->addPermission($perms['lire']);
        $manager->persist($roleAdmin);

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

        $manager->persist((new Affectation())->setUtilisateur($redacteurGlobal)->setRole($roleRedacteurGlobal)->setEtablissement($etabA));
        $manager->persist((new Affectation())->setUtilisateur($redacteurGlobal)->setRole($roleRedacteurGlobal)->setEtablissement($etabB));
        $manager->persist((new Affectation())->setUtilisateur($redacteurLocalA)->setRole($roleRedacteurLocal)->setEtablissement($etabA));
        $manager->persist((new Affectation())->setUtilisateur($redacteurLocalB)->setRole($roleRedacteurLocal)->setEtablissement($etabB));
        $manager->persist((new Affectation())->setUtilisateur($agentLecture)->setRole($roleAgentLecture)->setEtablissement($etabA));
        $manager->persist((new Affectation())->setUtilisateur($exploitantA)->setRole($roleExploitant)->setEtablissement($etabA));
        $manager->persist((new Affectation())->setUtilisateur($exploitantB)->setRole($roleExploitant)->setEtablissement($etabB));
        $manager->persist((new Affectation())->setUtilisateur($responsableA)->setRole($roleResponsableEtab)->setEtablissement($etabA));
        $manager->persist((new Affectation())->setUtilisateur($agentN1)->setRole($roleAgentN1)->setEtablissement($etabA));
        $manager->persist((new Affectation())->setUtilisateur($agentN1)->setRole($roleAgentN1)->setEtablissement($etabB));
        $manager->persist((new Affectation())->setUtilisateur($agentN2)->setRole($roleAgentN2)->setEtablissement($etabA));
        $manager->persist((new Affectation())->setUtilisateur($agentN2)->setRole($roleAgentN2)->setEtablissement($etabB));
        $manager->persist((new Affectation())->setUtilisateur($admin)->setRole($roleAdmin)->setEtablissement($etabA));
        $manager->persist((new Affectation())->setUtilisateur($admin)->setRole($roleAdmin)->setEtablissement($etabB));

        $manager->flush();
    }

    private function creerUtilisateur(ObjectManager $manager, string $email, string $nom): Utilisateur
    {
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom($nom)->setActif(true);
        $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, self::MDP));
        $manager->persist($utilisateur);

        return $utilisateur;
    }
}
