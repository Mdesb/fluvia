<?php

declare(strict_types=1);

namespace App\Fonctionnalite\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\DataFixtures\SocleFixtures;
use App\Fonctionnalite\Enum\Metier;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données du « Profil de fonctionnalités par établissement » : permissions `fonctionnalite.*`
 * accordées aux rôles administrateurs de démonstration (RG-SOCLE-02/03), preset `piscine` appliqué à
 * l'établissement A (« Piscine A »), preset `sport` appliqué à l'établissement B (établissement
 * « privé » de démonstration, même établissement que `SepaFixtures::$configPrive` / `RecouvrementFixtures`
 * pour la variante privée — cf. spec §« fixtures »).
 */
final class FonctionnaliteFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public function __construct(
        private readonly Fonctionnalites $fonctionnalites,
    ) {
    }

    public function getDependencies(): array
    {
        return [SocleFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        \assert($manager instanceof EntityManagerInterface);

        // --- Permissions fonctionnalite.* + octroi aux rôles administrateurs (RG-SOCLE-02/03) ---
        $perms = [];
        foreach (['lire', 'gerer'] as $action) {
            $perm = $this->permissionNommee($manager, 'fonctionnalite', $action);
            $manager->persist($perm);
            $perms[$action] = $perm;
        }
        foreach (['Administrateur groupe', 'Administrateur groupe B'] as $nomRole) {
            $role = $manager->getRepository(Role::class)->findOneBy(['nom' => $nomRole]);
            if ($role instanceof Role) {
                foreach ($perms as $perm) {
                    $role->addPermission($perm);
                }
            }
        }
        $manager->flush();

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $etabB = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        if (!$etabA instanceof Etablissement || !$etabB instanceof Etablissement) {
            return;
        }

        $this->fonctionnalites->appliquerPreset($etabA, Metier::Piscine);
        $this->fonctionnalites->appliquerPreset($etabB, Metier::Sport);
    }
}
