<?php

declare(strict_types=1);

namespace App\Boutique\DataFixtures;

use App\Boutique\Entity\CompteClient;
use App\Boutique\Entity\LignePanierEnLigne;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\SessionClient;
use App\Boutique\Entity\Vitrine;
use App\Boutique\Enum\TypeSessionClient;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\CreationCompteHandler;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\TypeExploitant;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Formule;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\PeriodiciteFormule;
use App\Offre\Enum\StatutProduit;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutCreneau;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données M3 Boutique (L8) : permissions `boutique.*` + rôles Gestionnaire/Responsable,
 * `RoleClientFinal` système, 1 vitrine en régie directe (établissement A, réutilise le
 * `ProfilExploitant` M6 existant) + 1 vitrine en groupe privé (établissement B, second profil créé
 * ici pour démontrer la commutation PayFiP/PSP CB), un catalogue de démonstration (produit simple,
 * produit timed-entry, abonnement SEPA, produit à support physique), 1 panier invité de démo, 1
 * compte client.
 */
final class BoutiqueFixtures extends Fixture implements DependentFixtureInterface
{
    public const GESTIONNAIRE_EMAIL = 'gestionnaire.boutique@itcotation.com';
    public const GESTIONNAIRE_MDP = 'aaa';
    public const RESPONSABLE_EMAIL = 'responsable.boutique@itcotation.com';
    public const RESPONSABLE_MDP = 'aaa';
    public const CLIENT_EMAIL = 'client.boutique@itcotation.com';
    public const CLIENT_MDP = 'aaa';

    public const PRODUIT_SIMPLE_CODE = 'PRD-BOU-SIMPLE';
    public const PRODUIT_TIMED_ENTRY_CODE = 'PRD-BOU-TIMED';
    public const PRODUIT_ABONNEMENT_CODE = 'PRD-BOU-ABO';
    public const PRODUIT_SUPPORT_PHYSIQUE_CODE = 'PRD-BOU-PHYSIQUE';

    /** @var list<string> Bundle de permissions `_soi` du rôle système (miroir de CreationCompteHandler). */
    private const PERMISSIONS_CLIENT_FINAL = [
        ['crm', 'lire_soi'], ['crm', 'modifier_soi'], ['crm', 'pmv_lire_soi'], ['crm', 'pmv_recharger_soi'],
        ['crm', 'consentement_gerer_soi'], ['reservation', 'reserver_soi'], ['reservation', 'lire_soi'],
        ['reservation', 'annuler_soi'], ['boutique', 'acheter_soi'], ['boutique', 'lire_soi'],
        ['boutique', 'gerer_famille_soi'], ['boutique', 'demander_remboursement_soi'],
    ];

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function getDependencies(): array
    {
        return [SocleFixtures::class, OffreFixtures::class, ComptaFixtures::class, CrmFixtures::class, ReservationFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $etabB = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        if (!$etabA instanceof Etablissement) {
            $manager->flush();

            return;
        }

        // --- Permissions boutique.* + octroi complet à l'administrateur (RG-SOCLE-02/03) ---
        $permBoutiqueTout = (new Permission())->setModule('boutique')->setAction('*');
        $manager->persist($permBoutiqueTout);
        $actions = [
            'gerer_vitrine', 'gerer_promo', 'gerer_connecteur_ota', 'lire', 'traiter_remboursement',
            'traiter_retrait', 'gerer', 'acheter_soi', 'lire_soi', 'gerer_famille_soi', 'demander_remboursement_soi',
        ];
        $permissions = [];
        foreach ($actions as $action) {
            $permissions[$action] = (new Permission())->setModule('boutique')->setAction($action);
            $manager->persist($permissions[$action]);
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permBoutiqueTout);
        }

        // --- Rôle « Gestionnaire boutique » (§3 spec-boutique.md) ---
        $roleGestionnaire = (new Role())->setNom('Gestionnaire boutique');
        foreach (['lire', 'gerer_vitrine', 'gerer_promo', 'gerer_connecteur_ota'] as $action) {
            $roleGestionnaire->addPermission($permissions[$action]);
        }
        $manager->persist($roleGestionnaire);
        $gestionnaire = $this->utilisateur($manager, self::GESTIONNAIRE_EMAIL, self::GESTIONNAIRE_MDP, 'Gestionnaire Boutique');
        $manager->persist((new Affectation())->setUtilisateur($gestionnaire)->setRole($roleGestionnaire)->setEtablissement($etabA));

        // --- Rôle « Responsable boutique » (traitement remboursement/retrait) ---
        $roleResponsable = (new Role())->setNom('Responsable boutique');
        foreach (['lire', 'traiter_remboursement', 'traiter_retrait'] as $action) {
            $roleResponsable->addPermission($permissions[$action]);
        }
        $manager->persist($roleResponsable);
        $responsable = $this->utilisateur($manager, self::RESPONSABLE_EMAIL, self::RESPONSABLE_MDP, 'Responsable Boutique');
        $manager->persist((new Affectation())->setUtilisateur($responsable)->setRole($roleResponsable)->setEtablissement($etabA));

        // --- Rôle système RoleClientFinal (§1.4 plan-boutique.md, idempotent) ---
        $roleClientFinal = $manager->getRepository(Role::class)->findOneBy(['nom' => CreationCompteHandler::ROLE_CLIENT_FINAL_NOM]);
        if (!$roleClientFinal instanceof Role) {
            $roleClientFinal = new Role();
            $roleClientFinal->setNom(CreationCompteHandler::ROLE_CLIENT_FINAL_NOM);
            foreach (self::PERMISSIONS_CLIENT_FINAL as [$module, $action]) {
                $perm = $manager->getRepository(Permission::class)->findOneBy(['module' => $module, 'action' => $action])
                    ?? $permissions[$action] ?? null;
                if (!$perm instanceof Permission) {
                    $perm = (new Permission())->setModule($module)->setAction($action);
                    $manager->persist($perm);
                }
                $roleClientFinal->addPermission($perm);
            }
            $manager->persist($roleClientFinal);
        }

        // --- Vitrine régie directe (établissement A, réutilise le ProfilExploitant M6 existant) ---
        $vitrineA = (new Vitrine())->setEtablissement($etabA)
            ->setLogo('/assets/vitrine-a-logo.svg')
            ->setCouleurs(['primaire' => '#0B6E4F', 'secondaire' => '#F4A300'])
            ->setLangues(['fr', 'en'])
            ->setCanauxActifs(['en_ligne', 'app'])
            ->setDelaiExpirationPanierMinutes(15);
        $manager->persist($vitrineA);

        $vitrineB = null;
        if ($etabB instanceof Etablissement) {
            // Second profil exploitant (privé), démonstration de la commutation RG-M3-11 — n'existe
            // pas déjà côté M6 (ComptaFixtures ne couvre que l'établissement A).
            $profilPrive = new ProfilExploitant();
            $profilPrive->setType(TypeExploitant::GroupePrive)
                ->setReferentielComptable(ReferentielComptable::Pcg)
                ->setSiren('130025265')
                ->setEtablissementPrincipal($etabB)
                ->addEtablissementRattache($etabB);
            $manager->persist($profilPrive);

            $vitrineB = (new Vitrine())->setEtablissement($etabB)
                ->setLogo('/assets/vitrine-b-logo.svg')
                ->setCouleurs(['primaire' => '#1B1F3B', 'secondaire' => '#E63946'])
                ->setLangues(['fr'])
                ->setCanauxActifs(['en_ligne'])
                ->setDelaiExpirationPanierMinutes(20);
            $manager->persist($vitrineB);
        }

        // --- Catalogue de démonstration (canal en_ligne, publié — distinct des produits OffreFixtures
        // qui restent en brouillon) ---
        $typeEntree = $manager->getRepository(TypeProduit::class)->findOneBy(['code' => OffreFixtures::TYPE_ENTREE]);
        $typeAbo = $manager->getRepository(TypeProduit::class)->findOneBy(['code' => OffreFixtures::TYPE_ABONNEMENT]);
        $tarifPlein = $manager->getRepository(TypeTarif::class)->findOneBy(['nom' => OffreFixtures::TARIF_PLEIN]);
        $saison = $manager->getRepository(\App\Offre\Entity\Saison::class)->findOneBy(['nom' => OffreFixtures::SAISON]);

        if ($typeEntree instanceof TypeProduit && $tarifPlein instanceof TypeTarif && $saison instanceof \App\Offre\Entity\Saison) {
            $simple = (new Produit())->setType($typeEntree)
                ->setLibelle(['fr' => 'Billet simple boutique'])->setLibelleRecherche('Billet simple boutique')
                ->setCode(self::PRODUIT_SIMPLE_CODE)->setCanaux(['guichet', 'en_ligne'])->setStatut(StatutProduit::Publie);
            $simple->addEtablissement($etabA);
            $manager->persist($simple);
            $simple->addGrille((new GrilleTarifaire())->setProduit($simple)->setTypeTarif($tarifPlein)->setSaison($saison)->setPrix('12.00'));
            $manager->persist($simple->getGrilles()->last());

            // --- Produit à support physique (click & collect, RG-M3-18) ---
            $physique = (new Produit())->setType($typeEntree)
                ->setLibelle(['fr' => 'Bracelet RFID boutique'])->setLibelleRecherche('Bracelet RFID boutique')
                ->setCode(self::PRODUIT_SUPPORT_PHYSIQUE_CODE)->setCanaux(['guichet', 'en_ligne'])
                ->setChampsPerso(['supportPhysique' => true])->setStatut(StatutProduit::Publie);
            $physique->addEtablissement($etabA);
            $manager->persist($physique);
            $physique->addGrille((new GrilleTarifaire())->setProduit($physique)->setTypeTarif($tarifPlein)->setSaison($saison)->setPrix('18.00'));
            $manager->persist($physique->getGrilles()->last());

            // --- Produit timed-entry (RG-M3-02) : Ressource + Créneau dédiés Boutique ---
            $ressource = (new Ressource())->setEtablissement($etabA)->setCodeType('salle')
                ->setLibelle('Visite guidée boutique')->setCapacitePropre(20);
            $manager->persist($ressource);

            $debutCreneau = (new \DateTimeImmutable('next monday'))->setTime(10, 0);
            $creneau = (new Creneau())->setRessource($ressource)
                ->setDebut($debutCreneau)->setFin($debutCreneau->modify('+1 hour'))
                ->setCapacite(20)->setEtablissement($etabA)->setStatut(StatutCreneau::Planifie);
            $manager->persist($creneau);

            $timedEntry = (new Produit())->setType($typeEntree)
                ->setLibelle(['fr' => 'Visite guidée (créneau)'])->setLibelleRecherche('Visite guidée (créneau)')
                ->setCode(self::PRODUIT_TIMED_ENTRY_CODE)->setCanaux(['guichet', 'en_ligne'])
                ->setChampsPerso(['timedEntry' => true, 'ressourceId' => (string) $ressource->getId()])
                ->setStatut(StatutProduit::Publie);
            $timedEntry->addEtablissement($etabA);
            $manager->persist($timedEntry);
            $timedEntry->addGrille((new GrilleTarifaire())->setProduit($timedEntry)->setTypeTarif($tarifPlein)->setSaison($saison)->setPrix('15.00'));
            $manager->persist($timedEntry->getGrilles()->last());

            // --- Panier invité de démonstration (RG-M3-03) ---
            $sessionDemo = (new SessionClient())->setToken(PanierProprietaireGuard::hacher('jeton-demo-boutique'))
                ->setType(TypeSessionClient::Invite)->setEtablissement($etabA);
            $manager->persist($sessionDemo);
            $panierDemo = (new PanierEnLigne())->setVitrine($vitrineA)->setSessionClient($sessionDemo)
                ->setEtablissement($etabA)->setDateExpiration(new \DateTimeImmutable('+15 minutes'));
            $manager->persist($panierDemo);
            $ligneDemo = (new LignePanierEnLigne())->setProduit($simple)->setQuantite(1)
                ->setExpirationA($panierDemo->getDateExpiration());
            $panierDemo->addLigne($ligneDemo);
            $manager->persist($ligneDemo);
        }

        if ($typeAbo instanceof TypeProduit && $tarifPlein instanceof TypeTarif && $saison instanceof \App\Offre\Entity\Saison) {
            // --- Abonnement SEPA en ligne (RG-M3-12/17) ---
            $formule = (new Formule())->setPeriodicite(PeriodiciteFormule::Mensuel)
                ->setDroitAcces(['mode' => 'illimite'])->setSepaActif(true)
                ->setRenouvellement(['auto' => true, 'prix' => 'fixe']);
            $abonnement = (new Produit())->setType($typeAbo)
                ->setLibelle(['fr' => 'Abonnement boutique (SEPA)'])->setLibelleRecherche('Abonnement boutique (SEPA)')
                ->setCode(self::PRODUIT_ABONNEMENT_CODE)->setCanaux(['guichet', 'en_ligne'])
                ->setFormule($formule)->setStatut(StatutProduit::Publie);
            $abonnement->addEtablissement($etabA);
            $manager->persist($abonnement);
            $abonnement->addGrille((new GrilleTarifaire())->setProduit($abonnement)->setTypeTarif($tarifPlein)->setSaison($saison)->setPrix('29.90'));
            $manager->persist($abonnement->getGrilles()->last());
        }

        // --- Point de retrait click & collect (RG-M3-18) ---
        $manager->persist((new Espace())->setNom('Guichet retrait boutique')->setEtablissement($etabA)->setType('guichet'));

        // --- Compte client de démonstration (US-L8-04/10) ---
        $clientDemo = (new Client())->setType(TypeClient::Physique)->setNom('Martin')->setPrenom('Camille')
            ->setEmail(self::CLIENT_EMAIL)->setDateNaissance(new \DateTimeImmutable('1992-03-14'))
            ->setStatut(StatutClient::Actif)->setEtablissementCreation($etabA)->setGroupe($etabA->getRegion()?->getGroupe());
        $manager->persist($clientDemo);

        $utilisateurClient = new Utilisateur();
        $utilisateurClient->setEmail(self::CLIENT_EMAIL)->setNom('Camille Martin')
            ->setMotDePasse($this->hasher->hashPassword($utilisateurClient, self::CLIENT_MDP))
            ->setStatut(StatutUtilisateur::Actif)->setRolesSecurite(['ROLE_USER'])->setClientLie($clientDemo->getId());
        $manager->persist($utilisateurClient);

        $compteDemo = (new CompteClient())->setUtilisateur($utilisateurClient)->setClient($clientDemo)
            ->setVitrineCreation($vitrineA)->setEtablissement($etabA);
        $manager->persist($compteDemo);
        $manager->persist((new Affectation())->setUtilisateur($utilisateurClient)->setRole($roleClientFinal)->setEtablissement($etabA));

        $manager->flush();
    }

    private function utilisateur(ObjectManager $manager, string $email, string $motDePasse, string $nom): Utilisateur
    {
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom($nom)->setStatut(StatutUtilisateur::Actif);
        $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, $motDePasse));
        $manager->persist($utilisateur);

        return $utilisateur;
    }
}
