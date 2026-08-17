<?php

declare(strict_types=1);

namespace App\DataFixtures;

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
    // Valeurs déterministes exploitées par les tests.
    public const ETAB_A_NOM = 'Piscine A';
    public const ETAB_B_NOM = 'Patinoire B';
    public const ADMIN_EMAIL = 'admin@itcotation.com';
    public const ADMIN_MDP = 'aaa';
    public const LECTEUR_EMAIL = 'lecteur@itcotation.com';
    public const LECTEUR_MDP = 'aaa';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $groupe = (new Groupe())->setNom('Groupe Loisirs Métropole');
        $manager->persist($groupe);

        $region = (new Region())->setNom('Région Est')->setGroupe($groupe);
        $manager->persist($region);

        $etabA = (new Etablissement())->setNom(self::ETAB_A_NOM)->setRegion($region)->setActif(true);
        $etabB = (new Etablissement())->setNom(self::ETAB_B_NOM)->setRegion($region)->setActif(true);
        $manager->persist($etabA);
        $manager->persist($etabB);

        // Permissions (RG-SOCLE-02).
        $permOrgGerer = (new Permission())->setModule('organisation')->setAction('gerer');
        $permSecGerer = (new Permission())->setModule('securite')->setAction('gerer');
        $permLireTout = (new Permission())->setModule('*')->setAction('lire');
        $manager->persist($permOrgGerer);
        $manager->persist($permSecGerer);
        $manager->persist($permLireTout);

        // Rôles (RG-SOCLE-03).
        $roleAdmin = (new Role())->setNom('Administrateur groupe');
        $roleAdmin->addPermission($permOrgGerer)->addPermission($permSecGerer)->addPermission($permLireTout);
        $manager->persist($roleAdmin);

        $roleLecteur = (new Role())->setNom('Lecture seule');
        $roleLecteur->addPermission($permLireTout);
        $manager->persist($roleLecteur);

        // Utilisateurs (RG-SOCLE-06 : mot de passe haché).
        $admin = (new Utilisateur())
            ->setEmail(self::ADMIN_EMAIL)
            ->setNom('Administratrice Socle')
            ->setActif(true)
            ->setRolesSecurite(['ROLE_ADMIN']);
        $admin->setMotDePasse($this->hasher->hashPassword($admin, self::ADMIN_MDP));
        $manager->persist($admin);

        $lecteur = (new Utilisateur())
            ->setEmail(self::LECTEUR_EMAIL)
            ->setNom('Lecteur Socle')
            ->setActif(true);
        $lecteur->setMotDePasse($this->hasher->hashPassword($lecteur, self::LECTEUR_MDP));
        $manager->persist($lecteur);

        // Affectations (RG-SOCLE-03/05) : admin sur A et B, lecteur sur A seulement.
        $manager->persist((new Affectation())->setUtilisateur($admin)->setRole($roleAdmin)->setEtablissement($etabA));
        $manager->persist((new Affectation())->setUtilisateur($admin)->setRole($roleAdmin)->setEtablissement($etabB));
        $manager->persist((new Affectation())->setUtilisateur($lecteur)->setRole($roleLecteur)->setEtablissement($etabA));

        $manager->flush();
    }
}
