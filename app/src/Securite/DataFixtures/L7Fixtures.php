<?php

declare(strict_types=1);

namespace App\Securite\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données L7 (M8 back-office & droits) : utilisateurs invités (valide/expiré), un second
 * administrateur d'établissement A (scénario « pas le dernier », CA-11), un administrateur
 * d'établissement restreint pour le plafond d'attribution (CA-10, RG-M8-09), un rôle dédié à la
 * délégation temporaire (CA-7/8/9).
 */
final class L7Fixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public const INVITE_EMAIL = 'invite.valide@itcotation.com';
    public const INVITE_JETON_CLAIR = 'jeton-invitation-test-clair-01';
    public const INVITE_EXPIRE_EMAIL = 'invite.expire@itcotation.com';
    public const INVITE_EXPIRE_JETON_CLAIR = 'jeton-invitation-test-clair-02';

    public const ADMIN2_EMAIL = 'admin2.etaba@itcotation.com';
    public const ADMIN2_MDP = 'Admin2EtabA#2026';

    public const RESP_A_EMAIL = 'respa.etaba@itcotation.com';
    public const RESP_A_MDP = 'aaa';
    public const ROLE_RESP_A_NOM = 'Administrateur établissement A (test)';
    public const ROLE_TROP_PUISSANT_NOM = 'Rôle trop puissant (test)';
    public const PERMISSION_DEMO_MODULE = 'demo';
    public const PERMISSION_DEMO_ACTION = 'special';

    public const ROLE_DELEGATION_NOM = 'Rôle délégation (test)';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function getDependencies(): array
    {
        return [SocleFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        \assert($etabA instanceof Etablissement);

        $permSecuriteGerer = $manager->getRepository(Permission::class)->findOneBy(['module' => 'securite', 'action' => 'gerer']);
        \assert($permSecuriteGerer instanceof Permission);
        $permOrganisationGerer = $manager->getRepository(Permission::class)->findOneBy(['module' => 'organisation', 'action' => 'gerer']);
        \assert($permOrganisationGerer instanceof Permission);

        // --- Référentiel `securite.lire`/`securite.exporter` (§5.2 plan) — dupliqué de la
        // migration de données puisque les tests recréent le schéma sans rejouer les migrations
        // (même convention que ComptaFixtures pour `compta.*`).
        //
        // IDEMPOTENT, ET CE N'EST PAS UNE PRÉCAUTION DÉCORATIVE. Ce doublon assumé rendait les deux
        // mondes incompatibles : sur une base construite par les MIGRATIONS — c'est-à-dire toute base
        // réelle, et la préproduction — un chargement de fixtures échouait sur
        // « Duplicate entry 'Caissier' for key 'uniq_role_nom' ». Le défaut ne s'était jamais vu parce
        // que personne n'avait jamais fait les deux : le harnais de test crée le schéma depuis les
        // entités et ne rejoue pas les migrations, donc les deux chemins ne se croisaient pas.
        // Constaté le 24/08 en voulant régénérer les données de démonstration de la préproduction.
        foreach ([['securite', 'lire'], ['securite', 'exporter']] as [$module, $action]) {
            if (null === $manager->getRepository(Permission::class)->findOneBy(['module' => $module, 'action' => $action])) {
                $manager->persist((new Permission())->setModule($module)->setAction($action));
            }
        }

        // --- Rôles-modèles vides (§5.3 plan, cahier M8-02) : idem, dupliqué de la migration. ---
        foreach (['Caissier', 'Responsable de site', 'Contrôleur', 'Comptable'] as $nomRoleModele) {
            if (null === $manager->getRepository(Role::class)->findOneBy(['nom' => $nomRoleModele])) {
                $manager->persist((new Role())->setNom($nomRoleModele)->setEstModele(true));
            }
        }

        // --- Utilisateurs invités (RG-M8-01, CA-1/CA-2) ---
        $invite = (new Utilisateur())->setEmail(self::INVITE_EMAIL)->setNom('Invité Valide');
        $invite->setMotDePasse($this->hasher->hashPassword($invite, bin2hex(random_bytes(16))));
        $invite->setStatut(StatutUtilisateur::Invite);
        $invite->setJetonInvitation(hash('sha256', self::INVITE_JETON_CLAIR));
        $invite->setJetonInvitationExpire(new \DateTimeImmutable('+72 hours'));
        $manager->persist($invite);

        $inviteExpire = (new Utilisateur())->setEmail(self::INVITE_EXPIRE_EMAIL)->setNom('Invité Expiré');
        $inviteExpire->setMotDePasse($this->hasher->hashPassword($inviteExpire, bin2hex(random_bytes(16))));
        $inviteExpire->setStatut(StatutUtilisateur::Invite);
        $inviteExpire->setJetonInvitation(hash('sha256', self::INVITE_EXPIRE_JETON_CLAIR));
        $inviteExpire->setJetonInvitationExpire(new \DateTimeImmutable('-1 hour'));
        $manager->persist($inviteExpire);

        // --- Second administrateur d'établissement A (CA-11, scénario « pas le dernier ») ---
        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        \assert($roleAdmin instanceof Role);
        $admin2 = (new Utilisateur())->setEmail(self::ADMIN2_EMAIL)->setNom('Second Administrateur A')->setActif(true);
        $admin2->setMotDePasse($this->hasher->hashPassword($admin2, self::ADMIN2_MDP));
        $manager->persist($admin2);
        $manager->persist((new Affectation())->setUtilisateur($admin2)->setRole($roleAdmin)->setEtablissement($etabA));

        // --- Administrateur d'établissement restreint (CA-10, RG-M8-09, plafond d'attribution) ---
        $roleRespA = (new Role())->setNom(self::ROLE_RESP_A_NOM);
        $roleRespA->addPermission($permSecuriteGerer)->addPermission($permOrganisationGerer);
        $manager->persist($roleRespA);

        $respA = (new Utilisateur())->setEmail(self::RESP_A_EMAIL)->setNom('Responsable Établissement A')->setActif(true);
        $respA->setMotDePasse($this->hasher->hashPassword($respA, self::RESP_A_MDP));
        $manager->persist($respA);
        $manager->persist((new Affectation())->setUtilisateur($respA)->setRole($roleRespA)->setEtablissement($etabA));

        $permDemo = $this->permissionNommee($manager, self::PERMISSION_DEMO_MODULE, self::PERMISSION_DEMO_ACTION);
        $manager->persist($permDemo);
        $roleTropPuissant = (new Role())->setNom(self::ROLE_TROP_PUISSANT_NOM);
        $roleTropPuissant->addPermission($permSecuriteGerer)->addPermission($permDemo);
        $manager->persist($roleTropPuissant);

        // --- Rôle dédié à la délégation temporaire (CA-7/8/9, US-L7-07) ---
        $roleDelegation = (new Role())->setNom(self::ROLE_DELEGATION_NOM);
        $roleDelegation->addPermission($permOrganisationGerer);
        $manager->persist($roleDelegation);

        $manager->flush();
    }
}
