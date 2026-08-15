<?php

declare(strict_types=1);

namespace App\Securite\Service;

use App\Securite\Entity\Role;

/**
 * « Rôle à privilèges » (RG-M8-06) : tout `Role` portant au moins une `Permission` du module
 * `securite` (`gerer`/`lire`/`exporter`) — couvre Administrateur, Administrateur d'établissement,
 * Responsable sécurité. Le MFA est obligatoire pour ces rôles (CA-4).
 */
final class RoleAPrivileges
{
    public function estAPrivileges(Role $role): bool
    {
        foreach ($role->getPermissions() as $permission) {
            if ($permission->getModule() === 'securite') {
                return true;
            }
        }

        return false;
    }
}
