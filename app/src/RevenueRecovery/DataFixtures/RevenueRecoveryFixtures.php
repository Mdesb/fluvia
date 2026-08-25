<?php

declare(strict_types=1);

namespace App\RevenueRecovery\DataFixtures;

use App\DataFixtures\SocleFixtures;
use App\Securite\Entity\Permission;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données de test du module `App\RevenueRecovery` (I1) : permissions
 * `revenue_recovery.read`/`revenue_recovery.configure`/`revenue_recovery.manage` (RG-SOCLE-02), même
 * patron que `App\SmartFlow\DataFixtures\SmartFlowFixtures`. Les tests recréent le schéma via
 * `doctrine:schema:create` (pas de rejeu de migrations) : ce jeu duplique le seed déjà posé par
 * `Version20260824220000.php`.
 */
final class RevenueRecoveryFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [SocleFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        foreach (['read', 'configure', 'manage'] as $action) {
            $permission = (new Permission())->setModule('revenue_recovery')->setAction($action);
            $manager->persist($permission);
        }

        $manager->flush();
    }
}
