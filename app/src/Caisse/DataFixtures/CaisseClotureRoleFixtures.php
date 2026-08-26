<?php

declare(strict_types=1);

namespace App\Caisse\DataFixtures;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données additif pour la clôture Z à rôle gradué (`spec-caisse-cloture-role.md` §6.1) :
 * permissions `caisse.voir_z`/`caisse.voir_ecart`, trois rôles/utilisateurs à droits différenciés —
 * un Caissier (comptage aveugle uniquement), un Régisseur (Z complet + alertes) sur l'établissement A,
 * et un Régisseur B (mêmes droits) affecté uniquement sur l'établissement B, nécessaire pour vérifier
 * le cloisonnement (CA-7). N'introduit pas de fichier de base de test partagé — cf.
 * `App\Tests\Caisse\CaisseClotureRoleApiTestCase`, dédiée à ce module.
 */
final class CaisseClotureRoleFixtures extends Fixture implements DependentFixtureInterface
{
    public const CAISSIER_EMAIL = 'caissier@itcotation.com';
    public const CAISSIER_MDP = 'aaa';
    public const REGISSEUR_EMAIL = 'regisseur@itcotation.com';
    public const REGISSEUR_MDP = 'aaa';
    public const REGISSEUR_B_EMAIL = 'regisseur-b@itcotation.com';
    public const REGISSEUR_B_MDP = 'aaa';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function getDependencies(): array
    {
        return [SocleFixtures::class, VenteFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // --- Permissions caisse.voir_z / caisse.voir_ecart (RG-CAISSEZ-03/06) ---
        $permVoirZ = $this->permission($manager, 'caisse', 'voir_z');
        $permVoirEcart = $this->permission($manager, 'caisse', 'voir_ecart');

        $permCloturer = $manager->getRepository(Permission::class)->findOneBy(['module' => 'caisse', 'action' => 'cloturer']);
        $permLireCaisse = $manager->getRepository(Permission::class)->findOneBy(['module' => 'caisse', 'action' => 'lire']);
        $permLireVente = $manager->getRepository(Permission::class)->findOneBy(['module' => 'vente', 'action' => 'lire']);
        // caisse.mouvement (semée par VenteFixtures) : nécessaire pour exercer POST /mouvements-caisse et
        // ainsi couvrir la non-régression de l'IDOR de cloisonnement (C11, MouvementCaisseProcessor).
        $permMouvement = $manager->getRepository(Permission::class)->findOneBy(['module' => 'caisse', 'action' => 'mouvement']);

        // --- Rôle « Caissier » = caisse.cloturer uniquement (comptage aveugle) ---
        $roleCaissier = $this->roleNomme($manager, 'Caissier');
        if ($permCloturer instanceof Permission) {
            $roleCaissier->addPermission($permCloturer);
        }

        // --- Rôle « Régisseur » = cloturer + voir_z + voir_ecart + lire (Z complet, alertes) ---
        $roleRegisseur = $this->roleNomme($manager, 'Régisseur');
        $roleRegisseur->addPermission($permVoirZ)->addPermission($permVoirEcart);
        if ($permCloturer instanceof Permission) {
            $roleRegisseur->addPermission($permCloturer);
        }
        if ($permLireCaisse instanceof Permission) {
            $roleRegisseur->addPermission($permLireCaisse);
        }
        if ($permLireVente instanceof Permission) {
            $roleRegisseur->addPermission($permLireVente);
        }
        if ($permMouvement instanceof Permission) {
            $roleRegisseur->addPermission($permMouvement);
        }

        // --- Rôle « Régisseur B » — mêmes permissions, affecté uniquement sur l'établissement B (CA-7) ---
        $roleRegisseurB = $this->roleNomme($manager, 'Régisseur B');
        $roleRegisseurB->addPermission($permVoirZ)->addPermission($permVoirEcart);
        if ($permCloturer instanceof Permission) {
            $roleRegisseurB->addPermission($permCloturer);
        }
        if ($permLireCaisse instanceof Permission) {
            $roleRegisseurB->addPermission($permLireCaisse);
        }
        if ($permLireVente instanceof Permission) {
            $roleRegisseurB->addPermission($permLireVente);
        }
        if ($permMouvement instanceof Permission) {
            $roleRegisseurB->addPermission($permMouvement);
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $etabB = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);

        $caissier = $this->utilisateur($manager, self::CAISSIER_EMAIL, 'Caissier Test', self::CAISSIER_MDP);

        $regisseur = $this->utilisateur($manager, self::REGISSEUR_EMAIL, 'Régisseur Test', self::REGISSEUR_MDP);

        $regisseurB = $this->utilisateur($manager, self::REGISSEUR_B_EMAIL, 'Régisseur B Test', self::REGISSEUR_B_MDP);

        if ($etabA instanceof Etablissement) {
            $this->affectation($manager, $caissier, $roleCaissier, $etabA);
            $this->affectation($manager, $regisseur, $roleRegisseur, $etabA);
        }
        if ($etabB instanceof Etablissement) {
            $this->affectation($manager, $regisseurB, $roleRegisseurB, $etabB);
        }

        $manager->flush();
    }

    /**
     * Cherche avant de créer — `Role.nom` porte une unicité **globale**, et quatorze fixtures créent
     * des rôles. Sans cette garde, un chargement complet échoue sur « Duplicate entry 'Caissier' » :
     * `Securite\DataFixtures\L7Fixtures` crée le même rôle.
     *
     * **Ce que ce défaut a coûté**, pour qu'on ne le reprenne pas ailleurs : le 24/08, un rechargement
     * des données de démonstration a échoué **après** avoir vidé la table des rattachements
     * droits-rôles. Les trente-quatre rôles de la préproduction se sont retrouvés à zéro droit, et
     * Maxime n'a plus pu tester qu'avec son propre compte — donc plus voir ses écrans avec les yeux
     * d'un caissier.
     *
     * **Pourquoi la suite ne le voyait pas** : le harnais recrée le schéma depuis les entités à chaque
     * classe de test, donc les fixtures partent toujours d'une base vide et sont chargées
     * sélectivement. Le seul geste qui révèle le défaut est de charger **deux fois**.
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

    /** Le couple (module, action) porte lui aussi une unicité. */
    private function permission(ObjectManager $manager, string $module, string $action): Permission
    {
        $existante = $manager->getRepository(Permission::class)->findOneBy(['module' => $module, 'action' => $action]);
        if ($existante instanceof Permission) {
            return $existante;
        }

        $permission = (new Permission())->setModule($module)->setAction($action);
        $manager->persist($permission);

        return $permission;
    }

    private function utilisateur(ObjectManager $manager, string $email, string $nom, string $motDePasse): Utilisateur
    {
        $existant = $manager->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        if ($existant instanceof Utilisateur) {
            return $existant;
        }

        $utilisateur = (new Utilisateur())->setEmail($email)->setNom($nom)->setActif(true);
        $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, $motDePasse));
        $manager->persist($utilisateur);

        return $utilisateur;
    }

    /**
     * `Affectation` ne porte pas d'unicité en base : un second chargement ne casserait pas, il
     * **empilerait** des doublons. Silencieux, et faux — les droits effectifs d'un utilisateur se
     * calculent en parcourant ses affectations.
     */
    private function affectation(ObjectManager $manager, Utilisateur $utilisateur, Role $role, Etablissement $etablissement): void
    {
        $existante = $manager->getRepository(Affectation::class)->findOneBy([
            'utilisateur' => $utilisateur,
            'role' => $role,
            'etablissement' => $etablissement,
        ]);
        if ($existante instanceof Affectation) {
            return;
        }

        $manager->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etablissement));
    }
}
