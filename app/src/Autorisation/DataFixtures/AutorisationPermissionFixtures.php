<?php

declare(strict_types=1);

namespace App\Autorisation\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Les permissions du module Autorisations — celui des élévations de privilèges.
 *
 * POURQUOI CE FICHIER EXISTE. `autorisation.lire` et `autorisation.gerer` sont exigées par onze
 * contrôles d'accès (`LimiteAutorisation`, `OperationSensible`, `DemandeEscalade`) et **n'étaient
 * créées par aucun code du dépôt** — ni fixture, ni migration, sous aucune forme. Aucun rôle ne
 * pouvait donc les détenir, et le module entier était inaccessible à tout le monde.
 *
 * CE QUI RENDAIT LE DÉFAUT INVISIBLE, et qui vaut d'être retenu : la base de préproduction LES A,
 * créées par un chemin qui n'existe plus. Le seul environnement où on aurait pu s'en apercevoir était
 * précisément celui qui masquait le problème. Il se serait manifesté chez le premier client, sur le
 * module des élévations de privilèges. Trouvé par `claude-H` en lisant, pas en testant.
 *
 * `autorisation.approuver` est semée ailleurs (fixtures Finance, pour le rôle Superviseur) : on ne la
 * recrée pas ici, on la réutilise si elle existe déjà.
 */
final class AutorisationPermissionFixtures extends Fixture
{
    use FixturesIdempotentes;

    /** Rôle qui administre les limites et lit le journal des escalades. */
    public const ADMIN_ROLE = 'Autorisations Administrateur';

    /** Rôle en lecture seule : voit les limites et les opérations sensibles, n'en change aucune. */
    public const READER_ROLE = 'Autorisations Lecture';

    public function load(ObjectManager $manager): void
    {
        $permissions = [];

        foreach (['lire', 'gerer'] as $action) {
            $existing = $manager->getRepository(Permission::class)
                ->findOneBy(['module' => 'autorisation', 'action' => $action]);

            if (!$existing instanceof Permission) {
                $existing = $this->permissionNommee($manager, 'autorisation', $action);
                $manager->persist($existing);
            }

            $permissions[$action] = $existing;
        }

        // L'administrateur gère les limites ; la lecture seule ne peut rien changer. Séparer les deux
        // n'est pas cosmétique sur ce module : `gerer` permet de relever le plafond au-delà duquel une
        // opération exige une escalade, donc de désarmer le contrôle qu'on est censé surveiller.
        $admin = $manager->getRepository(Role::class)->findOneBy(['nom' => self::ADMIN_ROLE])
            ?? $this->roleNomme($manager, self::ADMIN_ROLE);
        $admin->addPermission($permissions['gerer'])->addPermission($permissions['lire']);
        $manager->persist($admin);

        $reader = $manager->getRepository(Role::class)->findOneBy(['nom' => self::READER_ROLE])
            ?? $this->roleNomme($manager, self::READER_ROLE);
        $reader->addPermission($permissions['lire']);
        $manager->persist($reader);

        $manager->flush();
    }
}
