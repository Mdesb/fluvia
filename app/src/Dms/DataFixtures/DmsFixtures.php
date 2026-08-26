<?php

declare(strict_types=1);

namespace App\Dms\DataFixtures;

use App\DataFixtures\SocleFixtures;
use App\Dms\Entity\RetentionPolicy;
use App\Dms\Enum\DocumentCategory;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données du service transverse `App\Dms` (plan-dms.md T9) : permissions `dms.*` (accordées à
 * l'administrateur socle **sauf** `dms.manage_public_link`, §0.5 — jamais un rôle générique
 * d'administration), rôle dédié **« GED — Gestion des liens publics »** (`estModele: true`) portant
 * exactement `dms.read` + `dms.manage_public_link`, et le catalogue `RetentionPolicy` v1 (même valeurs
 * que le seed de la migration `Version2026*` — dupliqué ici car les tests recréent le schéma via
 * `doctrine:schema:create`, qui ne rejoue pas les migrations).
 */
final class DmsFixtures extends Fixture implements DependentFixtureInterface
{
    public const ROLE_LIENS_PUBLICS = 'GED — Gestion des liens publics';
    public const POLICY_ACCOUNTING = 'fr_accounting_10y';
    public const POLICY_HR = 'fr_hr_5y';

    public function getDependencies(): array
    {
        return [SocleFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // Idempotence (ordre A 26/08) : `Permission(module, action)`, `Role.nom` et
        // `RetentionPolicy.code`/`default_for_category` portent tous une unicité globale. Un
        // rechargement sur une base peuplée — régénération des données de démo préprod — échouait sinon
        // sur « Duplicate entry » dès `dms.read`. On cherche avant de créer.
        $permRead = $this->permissionDms($manager, 'read');
        $permWrite = $this->permissionDms($manager, 'write');
        $permDelete = $this->permissionDms($manager, 'delete');
        $permManageRetention = $this->permissionDms($manager, 'manage_retention');
        $permManagePublicLink = $this->permissionDms($manager, 'manage_public_link');

        // Administrateur groupe (socle) : toutes les permissions DMS SAUF manage_public_link — §0.5,
        // aucun rôle générique d'administration ne doit l'hériter (arbitrage D18 pt.1).
        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permRead)
                ->addPermission($permWrite)
                ->addPermission($permDelete)
                ->addPermission($permManageRetention);
        }

        // Rôle dédié (§0.5) : exactement dms.read (retrouver/consulter le document à lier) +
        // dms.manage_public_link — rien d'autre (DedicatedRolePublicLinkFixtureTest le garde).
        // `addPermission` est gardé par `contains` : réattacher sur un rôle réutilisé est sans effet.
        $roleLiensPublics = $manager->getRepository(Role::class)->findOneBy(['nom' => self::ROLE_LIENS_PUBLICS])
            ?? (new Role())->setNom(self::ROLE_LIENS_PUBLICS);
        $roleLiensPublics->setEstModele(true);
        $roleLiensPublics->addPermission($permRead)->addPermission($permManagePublicLink);
        $manager->persist($roleLiensPublics);

        // Catalogue RetentionPolicy v1 (RG-DMS-11) — ⚠ valeurs légales proposées par analogie, à
        // confirmer par un expert compta/RH avant mise en production (plan §14 pt.6).
        $this->retentionPolicy($manager, self::POLICY_ACCOUNTING, 120, 'dms.retention.fr_accounting_10y', DocumentCategory::AccountingPiece);
        $this->retentionPolicy($manager, self::POLICY_HR, 60, 'dms.retention.fr_hr_5y', DocumentCategory::HrDocument);

        $manager->flush();
    }

    /** Le couple `(module, action)` est unique — rendre l'existant plutôt qu'un doublon (ordre A 26/08). */
    private function permissionDms(ObjectManager $manager, string $action): Permission
    {
        $existante = $manager->getRepository(Permission::class)->findOneBy(['module' => 'dms', 'action' => $action]);
        if ($existante instanceof Permission) {
            return $existante;
        }

        $permission = (new Permission())->setModule('dms')->setAction($action);
        $manager->persist($permission);

        return $permission;
    }

    /** `code` et `default_for_category` sont uniques — rendre l'existant plutôt qu'un doublon. */
    private function retentionPolicy(
        ObjectManager $manager,
        string $code,
        int $durationMonths,
        string $legalBasisKey,
        DocumentCategory $defaultForCategory,
    ): RetentionPolicy {
        $existante = $manager->getRepository(RetentionPolicy::class)->findOneBy(['code' => $code]);
        if ($existante instanceof RetentionPolicy) {
            return $existante;
        }

        $policy = new RetentionPolicy($code, $durationMonths, $legalBasisKey, $defaultForCategory);
        $manager->persist($policy);

        return $policy;
    }
}
