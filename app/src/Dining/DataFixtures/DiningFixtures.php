<?php

declare(strict_types=1);

namespace App\Dining\DataFixtures;

use App\DataFixtures\SocleFixtures;
use App\Dining\Domain\CourseRef;
use App\Dining\Entity\DiningOrder;
use App\Dining\Entity\DiningOrderLine;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de donnees du module `App\Dining` (ACT-4) : les permissions `dining.*` accordees a
 * l administrateur socle, et **une addition ouverte dans CHACUN des deux etablissements**.
 *
 * L addition de l etablissement B n est pas decorative : c est le temoin du test de cloisonnement.
 * Sans donnee reelle hors perimetre, un test de non-regression d IDOR passe au vert pour la mauvaise
 * raison — il ne prouve alors que l absence de donnee, pas la presence d un filtre.
 */
final class DiningFixtures extends Fixture implements DependentFixtureInterface
{
    public const REFERENCE_A = 'ADD-TEST-A';
    public const REFERENCE_B = 'ADD-TEST-B';

    /** @return list<class-string> */
    public function getDependencies(): array
    {
        return [SocleFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // Les memes lignes sont posees par `Version20260901021200` : on reutilise si elles existent,
        // sinon toute base ayant recu les deux violerait `uniq_permission_module_action`.
        $permissions = [];
        foreach (['read', 'write', 'fire', 'void'] as $action) {
            $permission = $manager->getRepository(Permission::class)->findOneBy(['module' => 'dining', 'action' => $action]);
            if (!$permission instanceof Permission) {
                $permission = (new Permission())->setModule('dining')->setAction($action);
                $manager->persist($permission);
            }
            $permissions[] = $permission;
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            foreach ($permissions as $permission) {
                $roleAdmin->addPermission($permission);
            }
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $etabB = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        if (!$etabA instanceof Etablissement || !$etabB instanceof Etablissement) {
            $manager->flush();

            return;
        }

        $additionA = $this->addition($manager, $etabA, self::REFERENCE_A, '12', 4);
        $this->addition($manager, $etabB, self::REFERENCE_B, 'T3', 2);

        // Une ligne au brouillon sur A : une addition vide ne distingue pas « rien commande » de
        // « lignes invisibles ».
        $manager->persist(new DiningOrderLine(
            $additionA,
            CourseRef::of('plat', 2),
            'Entrecote',
            2,
            '24.50',
        ));

        $manager->flush();
    }

    private function addition(
        ObjectManager $manager,
        Etablissement $etablissement,
        string $reference,
        string $table,
        int $couverts,
    ): DiningOrder {
        $addition = new DiningOrder(
            $etablissement,
            $reference,
            $table,
            $couverts,
            new \DateTimeImmutable('2026-09-01 12:00:00'),
        );
        $manager->persist($addition);

        return $addition;
    }
}
