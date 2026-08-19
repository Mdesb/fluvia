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
        $permVoirZ = (new Permission())->setModule('caisse')->setAction('voir_z');
        $permVoirEcart = (new Permission())->setModule('caisse')->setAction('voir_ecart');
        $manager->persist($permVoirZ);
        $manager->persist($permVoirEcart);

        $permCloturer = $manager->getRepository(Permission::class)->findOneBy(['module' => 'caisse', 'action' => 'cloturer']);
        $permLireCaisse = $manager->getRepository(Permission::class)->findOneBy(['module' => 'caisse', 'action' => 'lire']);
        $permLireVente = $manager->getRepository(Permission::class)->findOneBy(['module' => 'vente', 'action' => 'lire']);

        // --- Rôle « Caissier » = caisse.cloturer uniquement (comptage aveugle) ---
        $roleCaissier = (new Role())->setNom('Caissier');
        if ($permCloturer instanceof Permission) {
            $roleCaissier->addPermission($permCloturer);
        }
        $manager->persist($roleCaissier);

        // --- Rôle « Régisseur » = cloturer + voir_z + voir_ecart + lire (Z complet, alertes) ---
        $roleRegisseur = (new Role())->setNom('Régisseur');
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
        $manager->persist($roleRegisseur);

        // --- Rôle « Régisseur B » — mêmes permissions, affecté uniquement sur l'établissement B (CA-7) ---
        $roleRegisseurB = (new Role())->setNom('Régisseur B');
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
        $manager->persist($roleRegisseurB);

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $etabB = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);

        $caissier = (new Utilisateur())
            ->setEmail(self::CAISSIER_EMAIL)
            ->setNom('Caissier Test')
            ->setActif(true);
        $caissier->setMotDePasse($this->hasher->hashPassword($caissier, self::CAISSIER_MDP));
        $manager->persist($caissier);

        $regisseur = (new Utilisateur())
            ->setEmail(self::REGISSEUR_EMAIL)
            ->setNom('Régisseur Test')
            ->setActif(true);
        $regisseur->setMotDePasse($this->hasher->hashPassword($regisseur, self::REGISSEUR_MDP));
        $manager->persist($regisseur);

        $regisseurB = (new Utilisateur())
            ->setEmail(self::REGISSEUR_B_EMAIL)
            ->setNom('Régisseur B Test')
            ->setActif(true);
        $regisseurB->setMotDePasse($this->hasher->hashPassword($regisseurB, self::REGISSEUR_B_MDP));
        $manager->persist($regisseurB);

        if ($etabA instanceof Etablissement) {
            $manager->persist((new Affectation())->setUtilisateur($caissier)->setRole($roleCaissier)->setEtablissement($etabA));
            $manager->persist((new Affectation())->setUtilisateur($regisseur)->setRole($roleRegisseur)->setEtablissement($etabA));
        }
        if ($etabB instanceof Etablissement) {
            $manager->persist((new Affectation())->setUtilisateur($regisseurB)->setRole($roleRegisseurB)->setEtablissement($etabB));
        }

        $manager->flush();
    }
}
