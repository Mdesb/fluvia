<?php

declare(strict_types=1);

namespace App\Recouvrement\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\DataFixtures\SocleFixtures;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données du moteur de recouvrement partagé (`App\Recouvrement`, extrait de `App\Sport`) :
 * permissions `recouvrement.*` accordées aux mêmes rôles administrateurs de démonstration que les
 * autres modules socle (RG-SOCLE-02/03).
 */
final class RecouvrementFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public function getDependencies(): array
    {
        return [SocleFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $perms = [];
        foreach (['lire', 'piloter', 'parametrer', 'forcer_acces', 'lire_soi', 'resoudre_impaye_soi'] as $action) {
            $perm = $this->permissionNommee($manager, 'recouvrement', $action);
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
    }
}
