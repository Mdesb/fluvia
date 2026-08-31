<?php

declare(strict_types=1);

namespace App\SmartFlow\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\DataFixtures\SocleFixtures;
use App\Securite\Entity\Permission;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données de test du module `App\SmartFlow` (I1 + I2) : permissions `smart_flow.read` (I2),
 * `smart_flow.reschedule_manage`/`smart_flow.reschedule_read_own` (I1, RG-SOCLE-02), `smart_flow.manage`
 * (paramétrage, revue de cohérence) — même patron que `App\Dms\DataFixtures\DmsFixtures`. Les tests
 * recréent le schéma via `doctrine:schema:create` (pas de rejeu de migrations) : ce jeu duplique le seed
 * déjà posé par `Version20260824110000.php`/`Version20260824210100.php`/`Version20260825090000.php`.
 */
final class SmartFlowFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public function getDependencies(): array
    {
        return [SocleFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        foreach (['read', 'reschedule_manage', 'reschedule_read_own', 'manage'] as $action) {
            $permission = $this->permissionNommee($manager, 'smart_flow', $action);
            $manager->persist($permission);
        }

        $manager->flush();
    }
}
