<?php

declare(strict_types=1);

namespace App\Marketing\DataFixtures;

use App\Crm\DataFixtures\CrmFixtures;
use App\DataFixtures\SocleFixtures;
use App\Marketing\Entity\LoyaltyRule;
use App\Marketing\Entity\LoyaltyTier;
use App\Marketing\Entity\ReferralProgram;
use App\Marketing\Entity\Segment;
use App\Marketing\Entity\SegmentCriteria;
use App\Organisation\Entity\Etablissement;
use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Securite\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Permissions du module Campagnes, et un segment de démonstration.
 *
 * **Une permission qu'aucun rôle ne détient protège aussi bien qu'un mur sans porte.** C'est le
 * constat du 24/08, et c'est pourquoi les deux permissions sont ici **rattachées** à
 * « Administrateur groupe » et pas seulement créées.
 *
 * ⚠ **Elles sont semées par fixture et non par migration**, contrairement à ce que suggérait la spec.
 * La raison a changé le 27/08 : les fixtures sont désormais idempotentes et leur section
 * permissions/rôles est délibérément placée **au-dessus** de la garde de bloc, donc rejouée à chaque
 * chargement. Un droit ajouté au code atteint donc une base existante — ce qui était précisément
 * l'argument en faveur de la migration.
 */
final class MarketingFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public const SEGMENT_DEMO = 'Clients à reconquérir';

    /** @return list<class-string> */
    public function getDependencies(): array
    {
        return [SocleFixtures::class, CrmFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $permTout = $this->permissionNommee($manager, 'campagne', '*');
        $permissions = [];
        foreach (['lire', 'gerer', 'lire_journal'] as $action) {
            $permissions[$action] = $this->permissionNommee($manager, 'campagne', $action);
        }

        // Chaque groupe gere ses campagnes : les deux administrateurs recoivent le droit complet.
        //
        // Le second n'est pas decoratif. Sans lui, le test de cloisonnement echouerait en 403 -- ce
        // qui est le bon comportement, mais ne prouve rien : un test qui passe parce que l'appelant
        // n'a pas le droit ne mesure pas le perimetre, il mesure l'absence de droit.
        foreach (['Administrateur groupe', 'Administrateur groupe B'] as $nomRole) {
            $role = $manager->getRepository(Role::class)->findOneBy(['nom' => $nomRole]);
            if ($role instanceof Role) {
                $role->addPermission($permTout);
            }
        }

        // ── LES DROITS DE LA FIDÉLITÉ ────────────────────────────────────────────────────────
        //
        // Trois droits et non un seul, parce que ce sont trois métiers : consulter un solde au
        // comptoir, accorder ou débiter des points, et fixer le barème. Le troisième engage
        // l'exploitant sur la durée — il ne se donne pas à qui tient la caisse.
        $permFideliteTout = $this->permissionNommee($manager, 'fidelite', '*');
        $droitsFidelite = [];
        foreach (['lire', 'gerer', 'parametrer'] as $action) {
            $droitsFidelite[$action] = $this->permissionNommee($manager, 'fidelite', $action);
        }

        foreach (['Administrateur groupe', 'Administrateur groupe B'] as $nomRole) {
            $role = $manager->getRepository(Role::class)->findOneBy(['nom' => $nomRole]);
            if ($role instanceof Role) {
                $role->addPermission($permFideliteTout);
                foreach ($droitsFidelite as $droit) {
                    $role->addPermission($droit);
                }
            }
        }

        // Un rôle « Chargé de campagnes » : il gère les campagnes sans toucher aux fiches clients.
        // La séparation compte — construire une audience n'est pas modifier un client, et le droit
        // de contacter mille personnes ne doit pas emporter celui d'en corriger une.
        $roleCampagne = $this->roleNomme($manager, 'Chargé de campagnes');
        foreach ($permissions as $permission) {
            $roleCampagne->addPermission($permission);
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        if (!$etabA instanceof Etablissement) {
            $manager->flush();

            return;
        }

        // ── LE BLOC DE DÉMONSTRATION NE SE POSE QU'UNE FOIS ──────────────────────────────────
        //
        // Les permissions et le rôle restent au-dessus : ils doivent être rejoués à chaque
        // chargement, sans quoi un droit ajouté au code n'atteindrait jamais une base existante.
        if ($manager->getRepository(Segment::class)->findOneBy(['label' => self::SEGMENT_DEMO]) !== null) {
            $manager->flush();

            return;
        }

        // Le segment que tout exploitant écrit en premier : ceux qui ne sont pas revenus.
        $manager->persist(
            (new Segment())
                ->setEstablishment($etabA)
                ->setLabel(self::SEGMENT_DEMO)
                ->setCriteria([SegmentCriteria::SANS_VISITE_DEPUIS_JOURS => 90])
        );

        // UN BARÈME DATÉ DE L'AN DERNIER, pour que les ventes existantes rapportent quelque chose.
        // Daté d'aujourd'hui, il ne s'appliquerait à rien et la démonstration afficherait zéro
        // partout — ce qui se lirait comme une panne.
        $manager->persist(
            (new LoyaltyRule())
                ->setEstablishment($etabA)
                ->setPointsPerEuro(1)
                ->setValidFrom(new \DateTimeImmutable('-1 year'))
        );

        foreach ([['Bronze', 100], ['Argent', 500], ['Or', 1500]] as [$libelle, $seuil]) {
            $manager->persist(
                (new LoyaltyTier())
                    ->setEstablishment($etabA)
                    ->setLabel($libelle)
                    ->setThreshold($seuil)
            );
        }

        // Le programme de parrainage : 200 points au parrain, dès 10 € encaissés par le filleul.
        // Le seuil est le cœur du programme — sans lui, on paierait des inscriptions.
        $manager->persist(
            (new ReferralProgram())
                ->setEstablishment($etabA)
                ->setRewardPoints(200)
                ->setMinimumPurchase('10.00')
        );

        $manager->flush();
    }
}
