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
    /**
     * Le joker `*` compte comme un privilège, et il faut le dire explicitement.
     *
     * Le contrôle comparait le module à la chaîne `'securite'`. Un rôle ne portant que `*.*` — donc
     * **tout-puissant**, `securite` compris — n'était donc pas reconnu comme à privilèges, et
     * **échappait au second facteur**. C'était exactement le cas d'« Administrateur groupe », que j'ai
     * créé le 24/08 dans `Version20260824231500` : le rôle le plus puissant d'une installation neuve
     * était précisément celui qui n'avait pas besoin de MFA. Trouvé par `claude-D`, dans ma migration.
     *
     * Rien ne le signalait — ni erreur, ni avertissement, ni test qui tombe. Et deux chemins le
     * consomment : `AffectationProcessor` et `MfaDesactivationProcessor`.
     *
     * **Pourquoi lire le joker plutôt que d'écrire « joker = privilèges » ailleurs** : la seconde
     * formulation est vraie aujourd'hui et le resterait, mais elle vivrait dans une convention. Ici
     * elle est dans le service qui décide, donc elle est vérifiable — et le test qui l'accompagne
     * tombera si quelqu'un la retire.
     *
     * La leçon générale, qui dépasse ce fichier : **un mécanisme de sécurité qui dépend de la FORME
     * d'une donnée plutôt que de son SENS finit toujours par se tromper.** Le joker *signifie* « tout,
     * y compris securite » ; le code, lui, comparait une chaîne.
     */
    public function estAPrivileges(Role $role): bool
    {
        foreach ($role->getPermissions() as $permission) {
            $module = $permission->getModule();

            if ($module === 'securite' || $module === '*') {
                return true;
            }
        }

        return false;
    }
}
