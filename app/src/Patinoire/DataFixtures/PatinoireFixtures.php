<?php

declare(strict_types=1);

namespace App\Patinoire\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Acces\Entity\EspaceAcces;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\DataFixtures\SocleFixtures;
use App\Offre\Entity\Saison;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Caution\Entity\Caution;
use App\Caution\Entity\GrilleRetenue as GrilleRetenueGenerique;
use App\Caution\Enum\ModeRetenue as ModeRetenueGenerique;
use App\Caution\Enum\StatutCaution as StatutCautionGenerique;
use App\Patinoire\Entity\Affutage;
use App\Patinoire\Entity\LocationPatins;
use App\Patinoire\Entity\ParcPatins;
use App\Patinoire\Entity\SaisonEphemere;
use App\Patinoire\Entity\ZonePatinoire;
use App\Patinoire\Enum\MotifRetenue;
use App\Patinoire\Enum\StatutAffutage;
use App\Patinoire\Enum\TypeAffutage;
use App\Patinoire\Enum\TypeZonePatinoire;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données de la verticale Patinoire (US-PATIN-01..10) : permissions `patinoire.*` + rôles
 * (Agent de comptoir, Technicien atelier, Gestionnaire glace) octroyées sur l'établissement A, 1 zone
 * glace + 1 zone gradins (spécialisation `EspaceAcces` L3), 1 parc de patins par pointure (41/42/43),
 * 1 location en cours, 1 grille de retenue, 1 affûtage démo (maintenance parc), 1 saison éphémère.
 */
final class PatinoireFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public const AGENT_EMAIL = 'agent.comptoir@patinoire.itcotation.com';
    public const AGENT_MDP = 'aaa';
    public const TECHNICIEN_EMAIL = 'technicien.atelier@patinoire.itcotation.com';
    public const TECHNICIEN_MDP = 'aaa';
    public const GESTIONNAIRE_GLACE_EMAIL = 'gestionnaire.glace@patinoire.itcotation.com';
    public const GESTIONNAIRE_GLACE_MDP = 'aaa';
    public const POINTURE_DEMO = 42;

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function getDependencies(): array
    {
        return [SocleFixtures::class, CrmFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        if (!$etabA instanceof Etablissement) {
            $manager->flush();

            return;
        }

        // --- Permissions patinoire.* + octroi complet à l'administrateur (RG-SOCLE-02/03) ---
        $permPatinoireTout = (new Permission())->setModule('patinoire')->setAction('*');
        $manager->persist($permPatinoireTout);
        $actions = [
            'lire', 'configurer', 'gerer_location', 'gerer_liste_attente', 'gerer_affutage',
            'arbitrer_surbooking', 'forcer_retenue',
        ];
        $permissions = [];
        foreach ($actions as $action) {
            $permissions[$action] = $this->permissionNommee($manager, 'patinoire', $action);
            $manager->persist($permissions[$action]);
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permPatinoireTout);
        }

        // --- Rôle « Agent de comptoir patinoire » (§3 spec-patinoire.md) ---
        $roleAgent = (new Role())->setNom('Agent de comptoir patinoire');
        foreach (['lire', 'gerer_location', 'gerer_liste_attente'] as $action) {
            $roleAgent->addPermission($permissions[$action]);
        }
        $manager->persist($roleAgent);
        $agent = $this->utilisateur($manager, self::AGENT_EMAIL, self::AGENT_MDP, 'Agent Comptoir Patinoire');
        $manager->persist((new Affectation())->setUtilisateur($agent)->setRole($roleAgent)->setEtablissement($etabA));

        // --- Rôle « Technicien / atelier » (§3 spec-patinoire.md) ---
        $roleTechnicien = (new Role())->setNom('Technicien atelier patinoire');
        foreach (['lire', 'gerer_affutage'] as $action) {
            $roleTechnicien->addPermission($permissions[$action]);
        }
        $manager->persist($roleTechnicien);
        $technicien = $this->utilisateur($manager, self::TECHNICIEN_EMAIL, self::TECHNICIEN_MDP, 'Technicien Atelier Patinoire');
        $manager->persist((new Affectation())->setUtilisateur($technicien)->setRole($roleTechnicien)->setEtablissement($etabA));

        // --- Rôle « Gestionnaire glace (planning) » (§3 spec-patinoire.md) ---
        $roleGestionnaireGlace = (new Role())->setNom('Gestionnaire glace patinoire');
        foreach (['lire', 'arbitrer_surbooking'] as $action) {
            $roleGestionnaireGlace->addPermission($permissions[$action]);
        }
        $manager->persist($roleGestionnaireGlace);
        $gestionnaireGlace = $this->utilisateur($manager, self::GESTIONNAIRE_GLACE_EMAIL, self::GESTIONNAIRE_GLACE_MDP, 'Gestionnaire Glace Patinoire');
        $manager->persist((new Affectation())->setUtilisateur($gestionnaireGlace)->setRole($roleGestionnaireGlace)->setEtablissement($etabA));

        // --- Zones glace / gradins (RG-PAT-02, spécialisation EspaceAcces L3, patron Poss) ---
        $espaceGlace = (new Espace())->setNom('Piste de glace')->setEtablissement($etabA)->setType('glace');
        $manager->persist($espaceGlace);
        $espaceAccesGlace = (new EspaceAcces())->setLibelle('Accès piste glace')->setEspaceSocle($espaceGlace)->setSeuilFmi(150);
        $manager->persist($espaceAccesGlace);
        $zoneGlace = (new ZonePatinoire())->setEspaceAcces($espaceAccesGlace)->setTypeZone(TypeZonePatinoire::Glace);
        $manager->persist($zoneGlace);

        $espaceGradins = (new Espace())->setNom('Gradins patinoire')->setEtablissement($etabA)->setType('gradins');
        $manager->persist($espaceGradins);
        $espaceAccesGradins = (new EspaceAcces())->setLibelle('Accès gradins')->setEspaceSocle($espaceGradins)->setSeuilFmi(400);
        $manager->persist($espaceAccesGradins);
        $zoneGradins = (new ZonePatinoire())->setEspaceAcces($espaceAccesGradins)->setTypeZone(TypeZonePatinoire::Gradins);
        $manager->persist($zoneGradins);

        // --- Parc de patins par pointure (RG-PAT-01, US-PATIN-01) : 41/42/43 ---
        $parcs = [];
        foreach ([41, self::POINTURE_DEMO, 43] as $pointure) {
            $parc = (new ParcPatins())->setEtablissement($etabA)->setPointure($pointure)->setQuantiteTotale(5);
            $manager->persist($parc);
            $parcs[$pointure] = $parc;
        }

        // --- Grille de retenue démo (établissement, motif casse, forfait 15 €) ---
        // Fine délégation (refactor caution générique) : portée par `App\Caution\Entity\GrilleRetenue`
        // (cible `patinoire.patins`), résolue par `App\Caution\Service\GestionCaution::resoudreGrille()`.
        $grille = (new GrilleRetenueGenerique())->setEtablissement($etabA)
            ->setTypeCible(\App\Patinoire\State\GrilleRetenueProvider::TYPE_CIBLE)
            ->setMotif(MotifRetenue::Casse->value)
            ->setMode(ModeRetenueGenerique::Forfait)->setMontantCentimes(1500);
        $manager->persist($grille);

        // --- Bénéficiaire démo (réutilise la famille Dupont de CrmFixtures) ---
        $payeur = $manager->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        $beneficiaire = $payeur instanceof Client
            ? $manager->getRepository(Beneficiaire::class)->findOneBy(['client' => $payeur])
            : null;

        if ($beneficiaire instanceof Beneficiaire) {
            // --- Location de patins en cours (US-PATIN-02, pointure démo) ---
            $location = (new LocationPatins())->setParcPatins($parcs[self::POINTURE_DEMO])->setBeneficiaire($beneficiaire);
            $manager->persist($location);
            $parcs[self::POINTURE_DEMO]->incrementerSortie(1);

            $caution = (new \App\Patinoire\Entity\CautionLocationPatins())->setLocation($location)->setMontant('15.00')
                ->setStatut(\App\Patinoire\Enum\StatutCaution::Encaissee)->setDateEncaissement(new \DateTimeImmutable());
            $manager->persist($caution);

            // Caution générique miroir (refactor caution générique).
            $cautionGenerique = (new Caution())
                ->setEtablissement($etabA)
                ->setTypeCible(\App\Patinoire\State\SortirPatinsProcessor::TYPE_CIBLE)
                ->setReferenceCible((string) $location->getId())
                ->setMontantCentimes(1500)
                ->setStatut(StatutCautionGenerique::Consignee)
                ->setDateConsignation(new \DateTimeImmutable());
            $manager->persist($cautionGenerique);
        }

        // --- Affûtage démo (maintenance du parc, pointure 41) ---
        $affutage = (new Affutage())->setType(TypeAffutage::MaintenanceParc)->setParcPatins($parcs[41])
            ->setTechnicien($technicien)->setEtablissement($etabA)->setStatut(StatutAffutage::EnCours);
        $manager->persist($affutage);
        $parcs[41]->incrementerEnAffutage(1);

        // --- Saison éphémère démo (RG-PAT-04, décembre → février) ---
        $saisonM1 = (new Saison())->setNom('Saison patinoire éphémère démo')
            ->setDateDebut(new \DateTimeImmutable('2026-12-01'))->setDateFin(new \DateTimeImmutable('2027-02-28'));
        $manager->persist($saisonM1);

        $saisonEphemere = (new SaisonEphemere())->setEtablissement($etabA)->setLibelle('Patinoire éphémère démo')
            ->setDateOuverture(new \DateTimeImmutable('2026-12-01'))->setDateFermeture(new \DateTimeImmutable('2027-02-28'))
            ->setFenetreVenteDebut(new \DateTimeImmutable('2026-10-01'))->setFenetreVenteFin(new \DateTimeImmutable('2027-02-28'))
            ->setSaisonM1($saisonM1)->setCatalogueAssocie([]);
        $manager->persist($saisonEphemere);

        $manager->flush();
    }

    private function utilisateur(ObjectManager $manager, string $email, string $motDePasse, string $nom): Utilisateur
    {
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom($nom)->setActif(true);
        $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, $motDePasse));
        $manager->persist($utilisateur);

        return $utilisateur;
    }
}
