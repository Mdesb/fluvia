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
        $permRead = (new Permission())->setModule('dms')->setAction('read');
        $permWrite = (new Permission())->setModule('dms')->setAction('write');
        $permDelete = (new Permission())->setModule('dms')->setAction('delete');
        $permManageRetention = (new Permission())->setModule('dms')->setAction('manage_retention');
        $permManagePublicLink = (new Permission())->setModule('dms')->setAction('manage_public_link');
        foreach ([$permRead, $permWrite, $permDelete, $permManageRetention, $permManagePublicLink] as $permission) {
            $manager->persist($permission);
        }

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
        $roleLiensPublics = (new Role())->setNom(self::ROLE_LIENS_PUBLICS)->setEstModele(true);
        $roleLiensPublics->addPermission($permRead)->addPermission($permManagePublicLink);
        $manager->persist($roleLiensPublics);

        // Catalogue RetentionPolicy v1 (RG-DMS-11) — ⚠ valeurs légales proposées par analogie, à
        // confirmer par un expert compta/RH avant mise en production (plan §14 pt.6).
        $politiqueCompta = new RetentionPolicy(
            self::POLICY_ACCOUNTING,
            120,
            'dms.retention.fr_accounting_10y',
            DocumentCategory::AccountingPiece,
        );
        $politiqueRh = new RetentionPolicy(
            self::POLICY_HR,
            60,
            'dms.retention.fr_hr_5y',
            DocumentCategory::HrDocument,
        );
        $manager->persist($politiqueCompta);
        $manager->persist($politiqueRh);

        $manager->flush();
    }
}
