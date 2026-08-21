<?php

declare(strict_types=1);

namespace App\Finance\DataFixtures;

use App\Compta\DataFixtures\ComptaFixtures;
use App\DataFixtures\SocleFixtures;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données de **test** du lot FIN-4 (`App\Finance\Treasury`) : les 3 permissions
 * `finance.treasury_*` accordées à l'administrateur. Réutilise **sans les recréer** le compte `512000`
 * (« Banque ») et le journal `BNQ` déjà seedés respectivement par `ComptaFixtures`/`FinanceFixtures`
 * (même constat que FIN-2 §7 point 9 de son plan — pas de doublon créé ici).
 */
final class TreasuryFixtures extends Fixture implements DependentFixtureInterface
{
    /** @var list<string> */
    public const ACTIONS = ['treasury_manage_account', 'treasury_import_statement', 'treasury_reconcile'];

    public function getDependencies(): array
    {
        return [SocleFixtures::class, ComptaFixtures::class, FinanceFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $perms = [];
        foreach (self::ACTIONS as $action) {
            $existante = $manager->getRepository(Permission::class)->findOneBy(['module' => 'finance', 'action' => $action]);
            $perms[$action] = $existante ?? (new Permission())->setModule('finance')->setAction($action);
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
