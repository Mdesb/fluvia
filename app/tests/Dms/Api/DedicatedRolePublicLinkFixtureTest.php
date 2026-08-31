<?php

declare(strict_types=1);

namespace App\Tests\Dms\Api;

use App\Dms\DataFixtures\DmsFixtures;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Tests\Dms\DmsApiTestCase;

/**
 * §0.5 du plan : sur les fixtures livrées, **seul** le rôle dédié « GED — Gestion des liens publics »
 * porte `dms.manage_public_link`. Limite explicite (consignée, pas garantie par ce test) : protège les
 * fixtures livrées, pas un administrateur qui composerait plus tard un rôle personnalisé.
 */
final class DedicatedRolePublicLinkFixtureTest extends DmsApiTestCase
{
    public function testSeulLeRoleDedieAPorteLaPermissionManagePublicLink(): void
    {
        $em = $this->em();
        $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'dms', 'action' => 'manage_public_link']);
        self::assertInstanceOf(Permission::class, $permission);

        /** @var list<Role> $roles */
        $roles = $em->getRepository(Role::class)->findAll();
        $rolesAvecLaPermission = [];
        foreach ($roles as $role) {
            if ($role->getPermissions()->contains($permission)) {
                $rolesAvecLaPermission[] = $role->getNom();
            }
        }

        self::assertSame(
            [DmsFixtures::ROLE_LIENS_PUBLICS],
            $rolesAvecLaPermission,
            'Seul le rôle dédié doit porter dms.manage_public_link sur les fixtures livrées (§0.5).',
        );
    }

    public function testRoleDedieEstModeleEtNePorteQueLesDeuxPermissionsAttendues(): void
    {
        $em = $this->em();
        $role = $em->getRepository(Role::class)->findOneBy(['nom' => DmsFixtures::ROLE_LIENS_PUBLICS]);
        self::assertInstanceOf(Role::class, $role);
        self::assertTrue($role->isEstModele());

        $codes = array_map(static fn (Permission $p): string => $p->getCode(), $role->getPermissions()->toArray());
        sort($codes);
        self::assertSame(['dms.manage_public_link', 'dms.read'], $codes);
    }

    public function testAucunRoleGeneriqueDadministrationNePorteLaPermission(): void
    {
        $em = $this->em();
        $roleAdmin = $em->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        self::assertInstanceOf(Role::class, $roleAdmin);

        $codes = array_map(static fn (Permission $p): string => $p->getCode(), $roleAdmin->getPermissions()->toArray());
        self::assertNotContains('dms.manage_public_link', $codes);
        self::assertContains('dms.read', $codes, 'L\'admin conserve read/write/delete/manage_retention.');
    }
}
