<?php

declare(strict_types=1);

namespace App\Caution\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Caution\Entity\Caution;
use App\Caution\Entity\GrilleRetenue;
use App\Caution\Enum\ModeRetenue;
use App\Caution\Enum\StatutCaution;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données du module socle `App\Caution` : permissions `caution.*` accordées à
 * l'administrateur (RG-SOCLE-02/03), une caution générique de démonstration (cible fictive
 * `demo.cible`) et une grille de retenue générale sur l'établissement A.
 */
final class CautionFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public const REFERENCE_CIBLE_DEMO = '00000000-0000-4000-8000-000000000001';

    public function getDependencies(): array
    {
        return [SocleFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $perms = [];
        foreach (['lire', 'piloter', 'parametrer', 'gerer', 'forcer'] as $action) {
            $perm = $this->permissionNommee($manager, 'caution', $action);
            $manager->persist($perm);
            $perms[$action] = $perm;
        }
        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            foreach ($perms as $perm) {
                $roleAdmin->addPermission($perm);
            }
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        if (!$etabA instanceof Etablissement) {
            $manager->flush();

            return;
        }

        // ── LE BLOC DE DEMONSTRATION NE SE POSE QU'UNE FOIS ──────────────────────────────────
        //
        // `caution_caution` porte `uniq_caution_cible_active` : une seule caution active par cible.
        // Sans garde, un second chargement s'y heurte. La sentinelle vise LA caution de demonstration
        // par sa cible, pas « une caution quelconque » : Piscine et Patinoire en creent aussi, et une
        // sentinelle large aurait fait sauter ce bloc quand l'une d'elles passe en premier.
        if ($manager->getRepository(\App\Caution\Entity\Caution::class)
            ->findOneBy(['referenceCible' => self::REFERENCE_CIBLE_DEMO]) !== null
        ) {
            $manager->flush();

            return;
        }

        $caution = new Caution();
        $caution->setEtablissement($etabA)
            ->setTypeCible('demo.cible')
            ->setReferenceCible(self::REFERENCE_CIBLE_DEMO)
            ->setMontantCentimes(1000)
            ->setStatut(StatutCaution::Consignee)
            ->setDateConsignation(new \DateTimeImmutable());
        $manager->persist($caution);

        $grille = new GrilleRetenue();
        $grille->setEtablissement($etabA)
            ->setTypeCible('demo.cible')
            ->setMotif('perte')
            ->setMode(ModeRetenue::Forfait)
            ->setMontantCentimes(500);
        $manager->persist($grille);

        $manager->flush();
    }
}
