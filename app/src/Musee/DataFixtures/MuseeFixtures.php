<?php

declare(strict_types=1);

namespace App\Musee\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeSeuil;
use App\Acces\Enum\TypeSupport;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\DataFixtures\SocleFixtures;
use App\Musee\Entity\Audioguide;
use App\Musee\Entity\ContingentGratuite;
use App\Musee\Entity\Exposition;
use App\Musee\Entity\Guide;
use App\Musee\Entity\ParametreMuseeEtablissement;
use App\Musee\Entity\PartenaireOTA;
use App\Musee\Entity\PassAnnuel;
use App\Musee\Entity\PolitiqueDelestage;
use App\Musee\Entity\QualificationLangueGuide;
use App\Musee\Entity\Salle;
use App\Musee\Entity\SousQuotaSalle;
use App\Musee\Enum\ModeDelestage;
use App\Musee\Enum\PerimetreContingent;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Formule;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Saison;
use App\Offre\Entity\TypeTarif;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;
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
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données de la verticale Musée : permissions `musee.*` + rôles, paramètres établissement, 1
 * exposition à jauge (+ créneaux horaires), 1 salle avec sous-quota + politique de délestage, 1
 * contingent de gratuités, 1 guide qualifié FR/EN, 1 partenaire OTA démo avec allocation, 1
 * audioguide, 1 pass annuel (US-MUSEE-01..11).
 */
final class MuseeFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public const GESTIONNAIRE_EMAIL = 'gestionnaire.musee@itcotation.com';
    public const GESTIONNAIRE_MDP = 'aaa';
    public const COORDINATEUR_EMAIL = 'coordinateur.visites@itcotation.com';
    public const COORDINATEUR_MDP = 'aaa';
    public const AGENT_EMAIL = 'agent.accueil.musee@itcotation.com';
    public const AGENT_MDP = 'aaa';
    public const GESTIONNAIRE_OTA_EMAIL = 'gestionnaire.ota@itcotation.com';
    public const GESTIONNAIRE_OTA_MDP = 'aaa';

    public const EXPOSITION_LIBELLE = 'Trésors d\'Égypte';
    public const SALLE_LIBELLE = 'Salle des sarcophages';
    public const PARTENAIRE_NOM = 'Tiqets (démo)';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function getDependencies(): array
    {
        return [SocleFixtures::class, OffreFixtures::class, CrmFixtures::class, ReservationFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        if (!$etabA instanceof Etablissement) {
            $manager->flush();

            return;
        }

        // --- Permissions musee.* + octroi complet à l'administrateur (RG-SOCLE-02/03) ---
        $permMuseeTout = $this->permissionNommee($manager, 'musee', '*');
        $manager->persist($permMuseeTout);
        $actions = ['lire', 'configurer', 'superviser_salle', 'gerer_visite', 'gerer_dossier_groupe', 'gerer_pass', 'gerer_ota', 'gerer'];
        $permissions = [];
        foreach ($actions as $action) {
            $permissions[$action] = $this->permissionNommee($manager, 'musee', $action);
            $manager->persist($permissions[$action]);
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permMuseeTout);
        }

        $permOffreCreer = $manager->getRepository(Permission::class)->findOneBy(['module' => 'offre', 'action' => 'creer']);
        $permOffreModifier = $manager->getRepository(Permission::class)->findOneBy(['module' => 'offre', 'action' => 'modifier']);

        // --- Rôle « Gestionnaire d'offre culturelle » (§3 spec-musee.md) ---
        $roleGestionnaire = $this->roleNomme($manager, 'Gestionnaire offre culturelle');
        foreach (['lire', 'configurer'] as $action) {
            $roleGestionnaire->addPermission($permissions[$action]);
        }
        if ($permOffreCreer instanceof Permission) {
            $roleGestionnaire->addPermission($permOffreCreer);
        }
        if ($permOffreModifier instanceof Permission) {
            $roleGestionnaire->addPermission($permOffreModifier);
        }
        $manager->persist($roleGestionnaire);
        $gestionnaire = $this->utilisateur($manager, self::GESTIONNAIRE_EMAIL, self::GESTIONNAIRE_MDP, 'Gestionnaire Offre Culturelle');
        $this->affectationUnique($manager, $gestionnaire, $roleGestionnaire, $etabA);

        // --- Rôle « Coordinateur de visites guidées » ---
        $roleCoordinateur = $this->roleNomme($manager, 'Coordinateur visites guidées');
        foreach (['lire', 'gerer_visite'] as $action) {
            $roleCoordinateur->addPermission($permissions[$action]);
        }
        $manager->persist($roleCoordinateur);
        $coordinateur = $this->utilisateur($manager, self::COORDINATEUR_EMAIL, self::COORDINATEUR_MDP, 'Coordinateur Visites Guidées');
        $this->affectationUnique($manager, $coordinateur, $roleCoordinateur, $etabA);

        // --- Rôle « Agent d'accueil / caisse » ---
        $roleAgent = $this->roleNomme($manager, 'Agent accueil musée');
        foreach (['lire', 'gerer_dossier_groupe', 'gerer_pass', 'superviser_salle'] as $action) {
            $roleAgent->addPermission($permissions[$action]);
        }
        $manager->persist($roleAgent);
        $agent = $this->utilisateur($manager, self::AGENT_EMAIL, self::AGENT_MDP, 'Agent Accueil Musée');
        $this->affectationUnique($manager, $agent, $roleAgent, $etabA);

        // --- Rôle « Gestionnaire de distribution OTA » ---
        $roleOta = $this->roleNomme($manager, 'Gestionnaire distribution OTA');
        foreach (['lire', 'gerer_ota'] as $action) {
            $roleOta->addPermission($permissions[$action]);
        }
        $manager->persist($roleOta);
        $gestionnaireOta = $this->utilisateur($manager, self::GESTIONNAIRE_OTA_EMAIL, self::GESTIONNAIRE_OTA_MDP, 'Gestionnaire Distribution OTA');
        $this->affectationUnique($manager, $gestionnaireOta, $roleOta, $etabA);

        // ── LE BLOC DE DÉMONSTRATION NE SE POSE QU'UNE FOIS ──────────────────────────────────
        //
        // Tout ce qui suit est un jeu de données cohérent, pas un référentiel : le reposer sur une
        // base qui l'a déjà écraserait ce qui a été corrigé à la main depuis, ou le dupliquerait
        // pour les entités sans contrainte d'unicité — silencieusement.
        //
        // Les permissions et les rôles, eux, restent AU-DESSUS de cette garde : ils doivent être
        // rejoués à chaque chargement, sans quoi un droit ajouté au code n'atteindrait jamais une
        // base existante.
        if ($manager->getRepository(Exposition::class)->findOneBy([]) !== null) {
            $manager->flush();

            return;
        }

        // --- Paramètres établissement (décision n°7 du plan) ---
        $parametre = (new ParametreMuseeEtablissement())->setEtablissement($etabA)
            ->setSeuilPastilleTendu(20)->setTauxRemiseAudioguideDefaut('20.00')->setDelaiOptionDossierGroupeJours(15)
            ->setModeSousQuotaSalleDefaut(ModeSeuil::Alerte);
        $manager->persist($parametre);

        $typeEntree = $manager->getRepository(TypeProduit::class)->findOneBy(['code' => OffreFixtures::TYPE_ENTREE]);
        $typeAbo = $manager->getRepository(TypeProduit::class)->findOneBy(['code' => OffreFixtures::TYPE_ABONNEMENT]);

        // --- Exposition à jauge « Trésors d'Égypte » (US-MUSEE-01/09, RG-MUS-01/06) ---
        $ressourceEntree = (new Ressource())->setEtablissement($etabA)->setCodeType('exposition')
            ->setLibelle(self::EXPOSITION_LIBELLE)->setCapacitePropre(1000);
        $manager->persist($ressourceEntree);

        $produitExpo = new Produit();
        if ($typeEntree instanceof TypeProduit) {
            $produitExpo->setType($typeEntree);
        }
        $produitExpo->setLibelle(['fr' => self::EXPOSITION_LIBELLE])
            ->setLibelleRecherche(self::EXPOSITION_LIBELLE)
            ->setCode('PRD-EXPO-EGYPTE')
            ->setCanaux(['guichet', 'en_ligne'])
            ->setReglePca(\App\Offre\Enum\ReglePca::Aucune)
            ->setTauxTva('10.00')
            ->setStatut(StatutProduit::Publie);
        $produitExpo->addEtablissement($etabA);
        $manager->persist($produitExpo);

        $expo = (new Exposition())->setProduit($produitExpo)
            ->setDateDebut(new \DateTimeImmutable('-1 month'))
            ->setDateFin(new \DateTimeImmutable('+6 months'))
            ->setAJauge(true)
            ->setJaugeGlobale(1000)
            ->setRessourceEntree($ressourceEntree)
            ->setEtablissement($etabA);
        $manager->persist($expo);

        // --- Créneaux horaires de démonstration (tranches, RG-MUS-01) ---
        $jour = (new \DateTimeImmutable('next tuesday'))->setTime(0, 0);
        $creneauMatin = (new Creneau())->setRessource($ressourceEntree)
            ->setDebut($jour->setTime(10, 0))->setFin($jour->setTime(11, 0))
            ->setCapacite(30)->setEtablissement($etabA)->setStatut(StatutCreneau::Planifie);
        $manager->persist($creneauMatin);

        $creneauApresMidi = (new Creneau())->setRessource($ressourceEntree)
            ->setDebut($jour->setTime(14, 0))->setFin($jour->setTime(15, 0))
            ->setCapacite(30)->setEtablissement($etabA)->setStatut(StatutCreneau::Planifie);
        $manager->persist($creneauApresMidi);

        // --- Contingent de gratuités dédié (décision actée §4.5, RG-MUS-03) ---
        $contingent = (new ContingentGratuite())->setPerimetre(PerimetreContingent::Creneau)
            ->setCreneau($creneauMatin)->setQuotaGratuitesDedie(5)->setQuotaConsomme(0)->setEtablissement($etabA);
        $manager->persist($contingent);

        // --- Salle avec sous-quota + politique de délestage (US-MUSEE-02, §4.2) ---
        $espaceSocle = (new Espace())->setNom(self::SALLE_LIBELLE)->setEtablissement($etabA)->setType('salle_exposition');
        $manager->persist($espaceSocle);

        $espaceAcces = (new EspaceAcces())->setLibelle(self::SALLE_LIBELLE)->setEspaceSocle($espaceSocle)
            ->setSeuilFmi(20)->setModeSeuil(ModeSeuil::Alerte)->setPreAlertePct(80);
        $manager->persist($espaceAcces);

        $salle = (new Salle())->setNom(self::SALLE_LIBELLE)->setEspace($espaceSocle)->setEspaceAcces($espaceAcces)->setExposition($expo);
        $manager->persist($salle);

        $sousQuota = (new SousQuotaSalle())->setSalle($salle)->setActif(true);
        $manager->persist($sousQuota);

        $delestage = (new PolitiqueDelestage())->setSousQuotaSalle($sousQuota)->setMode(ModeDelestage::AlerteSeule)
            ->setMessageAgent('Salle saturée : réguler l\'entrée, informer les visiteurs de l\'attente.');
        $manager->persist($delestage);

        // --- Guide qualifié FR/EN (US-MUSEE-03) ---
        $admin = $manager->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        if ($admin instanceof Utilisateur) {
            $guide = (new Guide())->setUtilisateur($admin)->setEtablissement($etabA);
            $manager->persist($guide);
            $manager->persist((new QualificationLangueGuide())->setGuide($guide)->setLangue('fr'));
            $manager->persist((new QualificationLangueGuide())->setGuide($guide)->setLangue('en'));
        }

        // --- Audioguide multilingue (US-MUSEE-11) ---
        $produitAudioguide = new Produit();
        if ($typeEntree instanceof TypeProduit) {
            $produitAudioguide->setType($typeEntree);
        }
        $produitAudioguide->setLibelle(['fr' => 'Audioguide'])->setLibelleRecherche('Audioguide')
            ->setCode('PRD-AUDIOGUIDE')->setCanaux(['guichet', 'en_ligne'])->setTauxTva('10.00')
            ->setStatut(StatutProduit::Publie);
        $produitAudioguide->addEtablissement($etabA);

        // ⚠ UN PRODUIT PUBLIE SANS TARIF EST UN ETAT QUE L'APPLICATION REFUSE DE PRODUIRE.
        //
        // `PublicationGuard` (RG-M1-09) exige un prix valide avant de publier. Cette fixture posait
        // `Publie` en dur sur l'entite, sans passer par le processeur : l'audioguide sans tarif se
        // retrouvait en vente sur la boutique PUBLIQUE, ajoutable au panier sous la phrase « le
        // tarif applicable est calcule et confirme a l'etape de paiement » -- alors qu'il n'y avait
        // rien a calculer.
        //
        // L'exposition, quelques lignes plus haut, a toujours eu son tarif. L'audioguide etait le
        // seul a sortir du rang : un oubli, pas un choix.
        $tarifPlein = $manager->getRepository(TypeTarif::class)->findOneBy(['nom' => OffreFixtures::TARIF_PLEIN]);
        $saisonCourante = $manager->getRepository(Saison::class)->findOneBy(['actif' => true]);
        if ($tarifPlein instanceof TypeTarif && $saisonCourante instanceof Saison) {
            // La grille se persiste A PART : `Produit#grilles` ne cascade pas, et Doctrine refuse au
            // flush une entite neuve atteinte par une relation non cascadee.
            $grilleAudioguide = (new GrilleTarifaire())
                ->setProduit($produitAudioguide)
                ->setTypeTarif($tarifPlein)
                ->setSaison($saisonCourante)
                ->setPrix('4.00');
            $produitAudioguide->addGrille($grilleAudioguide);
            $manager->persist($grilleAudioguide);
        }

        $manager->persist($produitAudioguide);

        $audioguide = (new Audioguide())->setProduit($produitAudioguide)->setLangues(['fr', 'en', 'es'])->setEtablissement($etabA);
        $manager->persist($audioguide);

        // --- Partenaire OTA démo + allocation de quota (US-MUSEE-07/08, RG-MUS-04) ---
        $partenaire = (new PartenaireOTA())->setNom(self::PARTENAIRE_NOM)->setTarifNet('8.00')->setCommission('15.00')
            ->setCodeConnecteur('stub')->setEtablissement($etabA);
        $manager->persist($partenaire);

        $allocation = (new \App\Musee\Entity\AllocationQuotaOTA())->setPartenaire($partenaire)->setCreneau($creneauMatin)
            ->setQuotaAlloue(10)->setQuotaConsomme(0);
        $manager->persist($allocation);

        // --- Pass annuel « Amis du musée » (US-MUSEE-10, RG-MUS-05) ---
        $payeur = $manager->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        if ($payeur instanceof Client && $typeAbo instanceof TypeProduit) {
            $beneficiairePayeur = $manager->getRepository(Beneficiaire::class)->findOneBy(['client' => $payeur]);
            if ($beneficiairePayeur instanceof Beneficiaire) {
                $formule = (new Formule())->setPeriodicite(PeriodiciteFormule::Annuel)
                    ->setDroitAcces(['mode' => 'illimite'])->setRenouvellement(['auto' => true, 'prix' => 'fixe']);
                $produitPass = (new Produit())->setType($typeAbo)->setLibelle(['fr' => 'Pass annuel Amis du musée'])
                    ->setLibelleRecherche('Pass annuel Amis du musée')->setCode('PRD-PASS-MUSEE')
                    ->setCanaux(['guichet', 'en_ligne'])->setFormule($formule)->setStatut(StatutProduit::Publie);
                $produitPass->addEtablissement($etabA);
                $manager->persist($produitPass);

                $support = (new Support())->setIdentifiant('MUSEE-PASS-DEMO-001')->setType(TypeSupport::Qr)->setEtablissement($etabA);
                $manager->persist($support);

                $pass = (new PassAnnuel())->setFormule($formule)->setAdherent($beneficiairePayeur)
                    ->setEcheance(new \DateTimeImmutable('+1 year'))->setAvantages(['coupe_file'])
                    ->setSupport($support)->setEtablissement($etabA);
                $manager->persist($pass);
            }
        }

        $manager->flush();
    }

    private function utilisateur(ObjectManager $manager, string $email, string $motDePasse, string $nom): Utilisateur
    {
        $existant = $manager->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        if ($existant instanceof Utilisateur) {
            // Le mot de passe n'est pas repose : le rejouer ecraserait un mot de passe change
            // depuis, et recalculerait un hachage pour rien a chaque chargement.
            return $existant->setNom($nom)->setActif(true);
        }

        $utilisateur = (new Utilisateur())->setEmail($email)->setNom($nom)->setActif(true);
        $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, $motDePasse));
        $manager->persist($utilisateur);

        return $utilisateur;
    }

    /**
     * Rend le role existant ou le cree. `Role.nom` porte une unicite **globale** : deux fixtures qui
     * creent le meme nom, ou un rechargement sur une base qui les a deja, echouent sur « Duplicate
     * entry » et laissent le chargement a mi-course. C'est ce qui a vide les droits des trente-quatre
     * roles de la preproduction le 24/08.
     *
     * Le harnais de test ne le voyait pas : il recree le schema a chaque classe et charge les fixtures
     * selectivement, donc elles partent toujours d'une base vide. Le seul endroit ou le defaut se voit
     * — un chargement complet — n'etait jamais visite.
     */
    private function roleNomme(ObjectManager $manager, string $nom): Role
    {
        $existant = $manager->getRepository(Role::class)->findOneBy(['nom' => $nom]);

        if ($existant instanceof Role) {
            return $existant;
        }

        $role = (new Role())->setNom($nom);
        $manager->persist($role);

        return $role;
    }
}
