<?php

declare(strict_types=1);

namespace App\Tests\Securite\Unit;

use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Service\RoleAPrivileges;
use PHPUnit\Framework\TestCase;

/**
 * `RoleAPrivileges` décide si le second facteur est **obligatoire** (RG-M8-06, CA-4).
 *
 * Le cas qui compte ici n'est pas le cas nominal : c'est le rôle qui ne porte QUE le joker `*.*`.
 * Il est tout-puissant — `securite` compris — et le contrôle comparait le module à la chaîne
 * `'securite'`, donc il ne le reconnaissait pas. Le rôle le plus puissant d'une installation neuve
 * échappait au MFA, sans erreur, sans avertissement, sans test qui tombe. Trouvé par `claude-D`
 * le 24/08 dans la migration de `claude-A`.
 */
final class RoleAPrivilegesTest extends TestCase
{
    public function testLeJokerCompteCommeUnPrivilege(): void
    {
        $role = (new Role())->setNom('Administrateur groupe');
        $role->addPermission((new Permission())->setModule('*')->setAction('*'));

        self::assertTrue(
            (new RoleAPrivileges())->estAPrivileges($role),
            "Un rôle qui porte le joker est tout-puissant : il DOIT exiger le second facteur."
        );
    }

    public function testUnPrivilegeDeSecuriteExplicteEstToujoursReconnu(): void
    {
        $role = (new Role())->setNom('Responsable sécurité');
        $role->addPermission((new Permission())->setModule('securite')->setAction('gerer'));

        self::assertTrue((new RoleAPrivileges())->estAPrivileges($role));
    }

    /**
     * Le pendant du premier test : sans lui, on pourrait rendre `estAPrivileges()` toujours vrai et
     * les deux autres passeraient. Un rôle ordinaire ne doit pas se voir imposer le MFA.
     */
    public function testUnRoleOrdinaireNexigePasLeSecondFacteur(): void
    {
        $role = (new Role())->setNom('Caissier');
        $role->addPermission((new Permission())->setModule('caisse')->setAction('lire'));
        $role->addPermission((new Permission())->setModule('vente')->setAction('creer'));

        self::assertFalse((new RoleAPrivileges())->estAPrivileges($role));
    }

    public function testUnRoleSansAucunDroitNexigePasLeSecondFacteur(): void
    {
        self::assertFalse((new RoleAPrivileges())->estAPrivileges((new Role())->setNom('Vide')));
    }
}
