<?php

declare(strict_types=1);

namespace App\Import\DataFixtures;

use App\DataFixtures\SocleFixtures;
use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données de **test** du lot I1 (`App\Import`) : les 4 permissions `import.*` accordées à
 * l'administrateur — même patron qu'`App\Finance\DataFixtures\TreasuryFixtures`.
 */
final class ImportFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    /** @var list<string> */
    public const ACTIONS = ['read', 'create', 'apply', 'revert'];

    public function getDependencies(): array
    {
        return [SocleFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $perms = [];
        foreach (self::ACTIONS as $action) {
            $existante = $manager->getRepository(Permission::class)->findOneBy(['module' => 'import', 'action' => $action]);
            $perms[$action] = $existante ?? $this->permissionNommee($manager, 'import', $action);
            if ($existante === null) {
                $manager->persist($perms[$action]);
            }
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            foreach ($perms as $perm) {
                $roleAdmin->addPermission($perm);
            }
        }

        $manager->flush();
    }
}
