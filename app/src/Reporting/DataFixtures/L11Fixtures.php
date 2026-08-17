<?php

declare(strict_types=1);

namespace App\Reporting\DataFixtures;

use App\Acces\Entity\Controleur;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\JaugeFmi;
use App\Acces\Entity\Passage;
use App\Acces\Enum\EtatControleur;
use App\Acces\Enum\ModeSeuil;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensPassage;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Caisse\Enum\EtatCaisse;
use App\Caisse\Enum\EtatSession;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\TypeExploitant;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Reporting\Entity\AxeAnalytique;
use App\Reporting\Entity\Indicateur;
use App\Reporting\Enum\ModeCalculIndicateur;
use App\Reporting\Enum\NatureIndicateur;
use App\Reporting\Enum\SourceModuleIndicateur;
use App\Reporting\Enum\TypeAxeAnalytique;
use App\Reporting\Enum\UniteIndicateur;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Paiement;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données M7 Reporting (L11) — autonome (ne dépend d'aucune fixture d'un autre module, pour
 * garder un contrôle complet de l'arbre Groupe › Région › Établissement nécessaire aux tests de
 * cloisonnement CA-1/CA-9/CA-10) :
 *  - Groupe « Groupe Démo Reporting » › Région A (Site A1 régie, Site A2 DSP) + Région B (Site B1).
 *  - 5 utilisateurs `reporting.*` : directeur de site (A1 seul), directeur régional (A1+A2 = Région A
 *    ENTIÈRE), DG (A1+A2+B1 = Groupe ENTIER), administrateur (+ reporting.configurer), utilisateur à
 *    affectations non contiguës (A1+B1, sans région/groupe entier couvert).
 *  - Site A1 (régie, contrôleur en ligne) : CA 120,00 €, 3 passages entrée, jauge FMI 10/50.
 *  - Site A2 (DSP, contrôleur HORS LIGNE — complétude partielle) : CA 80,00 €, 2 passages entrée,
 *    jauge FMI 25/30.
 *  - Site B1 (régie) : CA 40,00 €, 1 passage entrée, jauge FMI 5/20.
 */
final class L11Fixtures extends Fixture
{
    public const GROUPE_NOM = 'Groupe Démo Reporting';
    public const REGION_A_NOM = 'Région A Reporting';
    public const REGION_B_NOM = 'Région B Reporting';
    public const SITE_A1_NOM = 'Site A1 Reporting';
    public const SITE_A2_NOM = 'Site A2 Reporting';
    public const SITE_B1_NOM = 'Site B1 Reporting';

    public const MDP = 'aaa';
    public const EMAIL_SITE = 'reporting.site@itcotation.com';
    public const EMAIL_REGION = 'reporting.region@itcotation.com';
    public const EMAIL_GROUPE = 'reporting.groupe@itcotation.com';
    public const EMAIL_ADMIN = 'reporting.admin@itcotation.com';
    public const EMAIL_NON_CONTIGU = 'reporting.noncontigu@itcotation.com';

    public const CA_A1 = '120.00';
    public const CA_A2 = '80.00';
    public const CA_B1 = '40.00';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // --- Référentiel (§5.3 plan-reporting.md) : redondant avec la migration de données, mais
        // nécessaire car les tests recréent le schéma en base de test (mêmes valeurs que
        // Version20260816162600, cf. `ReportingApiTestCase`). ---
        $this->chargerReferentiel($manager);

        // --- Hiérarchie ---
        $groupe = (new Groupe())->setNom(self::GROUPE_NOM);
        $manager->persist($groupe);
        $regionA = (new Region())->setNom(self::REGION_A_NOM)->setGroupe($groupe);
        $regionB = (new Region())->setNom(self::REGION_B_NOM)->setGroupe($groupe);
        $manager->persist($regionA);
        $manager->persist($regionB);

        $siteA1 = (new Etablissement())->setNom(self::SITE_A1_NOM)->setRegion($regionA)->setActif(true);
        $siteA2 = (new Etablissement())->setNom(self::SITE_A2_NOM)->setRegion($regionA)->setActif(true);
        $siteB1 = (new Etablissement())->setNom(self::SITE_B1_NOM)->setRegion($regionB)->setActif(true);
        $manager->persist($siteA1);
        $manager->persist($siteA2);
        $manager->persist($siteB1);

        // --- Permissions reporting.* (redondant avec la migration, nécessaire car les tests
        // recréent le schéma en base de test, cf. `ReportingApiTestCase`) ---
        $permLire = (new Permission())->setModule('reporting')->setAction('lire');
        $permPlanifier = (new Permission())->setModule('reporting')->setAction('planifier');
        $permConfigurer = (new Permission())->setModule('reporting')->setAction('configurer');
        $manager->persist($permLire);
        $manager->persist($permPlanifier);
        $manager->persist($permConfigurer);

        $roleSite = (new Role())->setNom('Reporting Directeur Site');
        $roleSite->addPermission($permLire);
        $roleRegion = (new Role())->setNom('Reporting Directeur Régional');
        $roleRegion->addPermission($permLire)->addPermission($permPlanifier);
        $roleGroupe = (new Role())->setNom('Reporting Direction Générale');
        $roleGroupe->addPermission($permLire)->addPermission($permPlanifier);
        $roleAdmin = (new Role())->setNom('Reporting Administrateur');
        $roleAdmin->addPermission($permLire)->addPermission($permPlanifier)->addPermission($permConfigurer);
        foreach ([$roleSite, $roleRegion, $roleGroupe, $roleAdmin] as $role) {
            $manager->persist($role);
        }

        $utilisateurSite = $this->creerUtilisateur($manager, self::EMAIL_SITE, 'Directeur Site A1');
        $utilisateurRegion = $this->creerUtilisateur($manager, self::EMAIL_REGION, 'Directeur Région A');
        $utilisateurGroupe = $this->creerUtilisateur($manager, self::EMAIL_GROUPE, 'Direction Générale');
        $utilisateurAdmin = $this->creerUtilisateur($manager, self::EMAIL_ADMIN, 'Administrateur Reporting');
        $utilisateurNonContigu = $this->creerUtilisateur($manager, self::EMAIL_NON_CONTIGU, 'Utilisateur Non Contigu');

        // Directeur de site : A1 uniquement.
        $manager->persist((new Affectation())->setUtilisateur($utilisateurSite)->setRole($roleSite)->setEtablissement($siteA1));
        // Directeur régional : A1 + A2 = Région A ENTIÈRE.
        $manager->persist((new Affectation())->setUtilisateur($utilisateurRegion)->setRole($roleRegion)->setEtablissement($siteA1));
        $manager->persist((new Affectation())->setUtilisateur($utilisateurRegion)->setRole($roleRegion)->setEtablissement($siteA2));
        // DG : A1 + A2 + B1 = Groupe ENTIER.
        $manager->persist((new Affectation())->setUtilisateur($utilisateurGroupe)->setRole($roleGroupe)->setEtablissement($siteA1));
        $manager->persist((new Affectation())->setUtilisateur($utilisateurGroupe)->setRole($roleGroupe)->setEtablissement($siteA2));
        $manager->persist((new Affectation())->setUtilisateur($utilisateurGroupe)->setRole($roleGroupe)->setEtablissement($siteB1));
        // Administrateur : idem DG + reporting.configurer.
        $manager->persist((new Affectation())->setUtilisateur($utilisateurAdmin)->setRole($roleAdmin)->setEtablissement($siteA1));
        $manager->persist((new Affectation())->setUtilisateur($utilisateurAdmin)->setRole($roleAdmin)->setEtablissement($siteA2));
        $manager->persist((new Affectation())->setUtilisateur($utilisateurAdmin)->setRole($roleAdmin)->setEtablissement($siteB1));
        // Non contigu : A1 + B1 (aucune région/groupe entièrement couverte, cas limite §7 spec).
        $manager->persist((new Affectation())->setUtilisateur($utilisateurNonContigu)->setRole($roleSite)->setEtablissement($siteA1));
        $manager->persist((new Affectation())->setUtilisateur($utilisateurNonContigu)->setRole($roleSite)->setEtablissement($siteB1));

        // --- Profils exploitant (RG-M6-01, RG-REPORT-09) : A1 régie, A2 DSP, B1 régie. ---
        $profilA1 = (new ProfilExploitant())->setType(TypeExploitant::RegieDirecte)->setSiren('111111111')->setEtablissementPrincipal($siteA1);
        $profilA1->addEtablissementRattache($siteA1);
        $profilA2 = (new ProfilExploitant())->setType(TypeExploitant::Dsp)->setSiren('222222222')->setEtablissementPrincipal($siteA2);
        $profilA2->addEtablissementRattache($siteA2);
        $profilB1 = (new ProfilExploitant())->setType(TypeExploitant::RegieDirecte)->setSiren('333333333')->setEtablissementPrincipal($siteB1);
        $profilB1->addEtablissementRattache($siteB1);
        $manager->persist($profilA1);
        $manager->persist($profilA2);
        $manager->persist($profilB1);

        // --- Ventes (M2) : CA du jour par site ---
        $this->creerVenteEtSession($manager, $siteA1, self::CA_A1, $utilisateurAdmin);
        $this->creerVenteEtSession($manager, $siteA2, self::CA_A2, $utilisateurAdmin);
        $this->creerVenteEtSession($manager, $siteB1, self::CA_B1, $utilisateurAdmin);

        // --- Accès : espace + contrôleur + jauge FMI + passages entrée (fréquentation) ---
        // A1 : contrôleur EN LIGNE (complet), jauge 10/50.
        $this->creerTopologieAcces($manager, $siteA1, self::SITE_A1_NOM, EtatControleur::EnLigne, new \DateTimeImmutable(), 10, 50, 3);
        // A2 : contrôleur HORS LIGNE (RG-REPORT-11, CA-10), jauge 25/30 (site le plus critique de la Région A).
        $this->creerTopologieAcces($manager, $siteA2, self::SITE_A2_NOM, EtatControleur::HorsLigne, null, 25, 30, 2);
        // B1 : contrôleur EN LIGNE.
        $this->creerTopologieAcces($manager, $siteB1, self::SITE_B1_NOM, EtatControleur::EnLigne, new \DateTimeImmutable(), 5, 20, 1);

        $manager->flush();
    }

    private function creerUtilisateur(ObjectManager $manager, string $email, string $nom): Utilisateur
    {
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom($nom)->setActif(true);
        $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, self::MDP));
        $manager->persist($utilisateur);

        return $utilisateur;
    }

    private function creerVenteEtSession(ObjectManager $manager, Etablissement $etablissement, string $total, Utilisateur $operateur): void
    {
        $pdv = (new PointDeVente())->setLibelle('PDV ' . $etablissement->getNom())->setEtablissement($etablissement)->setMoyensAutorises(['especes']);
        $manager->persist($pdv);
        $caisse = (new Caisse())->setLibelle('Caisse ' . $etablissement->getNom())->setPointDeVente($pdv)->setEtat(EtatCaisse::Ouverte);
        $manager->persist($caisse);

        $session = (new SessionCaisse())
            ->setNumero('SESS-' . $etablissement->getNom())
            ->setPointDeVente($pdv)
            ->setCaisse($caisse)
            ->setRegisseur($operateur)
            ->setOperateur($operateur)
            ->setFondDeCaisse('50.00')
            ->setEtat(EtatSession::Ouverte)
            ->setEtablissement($etablissement);
        $manager->persist($session);

        $vente = (new Vente())
            ->setNumero('V-' . $etablissement->getNom())
            ->setSession($session)
            ->setEtablissement($etablissement)
            ->setStatut(StatutVente::Validee)
            ->setTotal($total)
            ->setResteAPayer('0.00');
        $manager->persist($vente);

        $paiement = (new Paiement())->setVente($vente)->setMoyenCode('especes')->setMontant($total);
        $manager->persist($paiement);
    }

    private function creerTopologieAcces(
        ObjectManager $manager,
        Etablissement $etablissement,
        string $libellePrefixe,
        EtatControleur $etatControleur,
        ?\DateTimeImmutable $dernierHeartbeat,
        int $valeurCourante,
        int $seuil,
        int $nombrePassagesEntree,
    ): void {
        $espaceSocle = (new Espace())->setNom('Zone ' . $libellePrefixe)->setEtablissement($etablissement)->setType('zone');
        $manager->persist($espaceSocle);

        $espaceAcces = (new EspaceAcces())
            ->setLibelle('Accès ' . $libellePrefixe)
            ->setEspaceSocle($espaceSocle)
            ->setSeuilFmi($seuil)
            ->setModeSeuil(ModeSeuil::Blocage);
        $manager->persist($espaceAcces);

        $controleur = (new Controleur())
            ->setLibelle('Contrôleur ' . $libellePrefixe)
            ->setEspace($espaceAcces)
            ->setItboxRef('ITBOX-' . $libellePrefixe)
            ->setEtat($etatControleur)
            ->setDernierHeartbeat($dernierHeartbeat);
        $manager->persist($controleur);

        // `JaugeFmiSyncListener` (module Accès, onFlush) crée AUTOMATIQUEMENT une `JaugeFmi` 1-1 à
        // la persistance de l'`EspaceAcces` (miroir seuil/mode) : flush intermédiaire nécessaire pour
        // récupérer cette jauge auto-créée et n'en piloter QUE `valeurCourante`/`cumulJour`, plutôt
        // que d'en persister une seconde (violerait `uniq_jauge_espace`).
        $manager->flush();
        $jauge = $manager->getRepository(JaugeFmi::class)->findOneBy(['espace' => $espaceAcces]);
        \assert($jauge instanceof JaugeFmi);
        $jauge->setValeurCourante($valeurCourante)->setCumulJour($nombrePassagesEntree);

        for ($i = 0; $i < $nombrePassagesEntree; ++$i) {
            $passage = (new Passage())
                ->setEspace($espaceAcces)
                ->setControleur($controleur)
                ->setSens(SensPassage::Entree)
                ->setResultat(ResultatPassage::Valide)
                ->setEtablissement($etablissement);
            $manager->persist($passage);
        }
    }

    private function chargerReferentiel(ObjectManager $manager): void
    {
        $axes = [
            ['site', 'Site', TypeAxeAnalytique::Site, null, false],
            ['activite', 'Activité', TypeAxeAnalytique::Activite, null, false],
            ['produit', 'Produit', TypeAxeAnalytique::Produit, null, false],
            ['categorie', 'Catégorie', TypeAxeAnalytique::Categorie, null, true],
            ['periode', 'Période', TypeAxeAnalytique::Periode, ['jour', 'semaine', 'mois', 'annee'], false],
            ['canal', 'Canal', TypeAxeAnalytique::Canal, null, true],
        ];
        foreach ($axes as [$code, $libelle, $type, $granularites, $estExtension]) {
            $axe = (new AxeAnalytique())->setCode($code)->setLibelle($libelle)->setType($type)->setEstExtension($estExtension);
            if ($granularites !== null) {
                $axe->setGranularites($granularites);
            }
            $manager->persist($axe);
        }

        $indicateurs = [
            ['CA', 'Chiffre d\'affaires encaissé', UniteIndicateur::Euro, ModeCalculIndicateur::Somme, NatureIndicateur::Cumule, SourceModuleIndicateur::Vente],
            ['FREQUENTATION_CUMULEE', 'Fréquentation cumulée', UniteIndicateur::Nombre, ModeCalculIndicateur::Somme, NatureIndicateur::Cumule, SourceModuleIndicateur::Acces],
            ['FMI_MAX', 'FMI max (présence simultanée maximale, site)', UniteIndicateur::Nombre, ModeCalculIndicateur::Max, NatureIndicateur::Instantane, SourceModuleIndicateur::Acces],
            ['FMI_MAX_SOMME_SITES', 'Somme des FMI max des sites', UniteIndicateur::Nombre, ModeCalculIndicateur::Somme, NatureIndicateur::Instantane, SourceModuleIndicateur::Acces],
            ['FMI_MAX_SITE_CRITIQUE', 'FMI max — site le plus critique', UniteIndicateur::Nombre, ModeCalculIndicateur::Max, NatureIndicateur::Instantane, SourceModuleIndicateur::Acces],
            ['TAUX_REMPLISSAGE', 'Taux de remplissage', UniteIndicateur::Pourcentage, ModeCalculIndicateur::Moyenne, NatureIndicateur::Cumule, SourceModuleIndicateur::Reservation],
            ['NO_SHOW', 'No-show', UniteIndicateur::Nombre, ModeCalculIndicateur::Somme, NatureIndicateur::Cumule, SourceModuleIndicateur::Reservation],
            ['IMPAYES', 'Impayés (montant)', UniteIndicateur::Euro, ModeCalculIndicateur::Somme, NatureIndicateur::Cumule, SourceModuleIndicateur::Recouvrement],
            ['FOND_CAISSE', 'Fond de caisse théorique', UniteIndicateur::Euro, ModeCalculIndicateur::Somme, NatureIndicateur::Instantane, SourceModuleIndicateur::Compta],
        ];
        foreach ($indicateurs as [$code, $libelle, $unite, $modeCalcul, $nature, $sourceModule]) {
            $indicateur = (new Indicateur())
                ->setCode($code)
                ->setLibelle($libelle)
                ->setUnite($unite)
                ->setModeCalcul($modeCalcul)
                ->setNature($nature)
                ->setSourceModule($sourceModule)
                ->setSeuilCompletudeMinutes(60);
            $manager->persist($indicateur);
        }

        $manager->flush();
    }
}
