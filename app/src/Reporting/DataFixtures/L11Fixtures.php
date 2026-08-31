<?php

declare(strict_types=1);

namespace App\Reporting\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
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
    use FixturesIdempotentes;

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
        $groupe = $this->parNom($manager, Groupe::class, self::GROUPE_NOM);

        $regionA = $this->parNom($manager, Region::class, self::REGION_A_NOM);
        $regionA->setGroupe($groupe);
        $regionB = $this->parNom($manager, Region::class, self::REGION_B_NOM);
        $regionB->setGroupe($groupe);

        $siteA1 = $this->parNom($manager, Etablissement::class, self::SITE_A1_NOM);
        $siteA1->setRegion($regionA)->setActif(true);
        $siteA2 = $this->parNom($manager, Etablissement::class, self::SITE_A2_NOM);
        $siteA2->setRegion($regionA)->setActif(true);
        $siteB1 = $this->parNom($manager, Etablissement::class, self::SITE_B1_NOM);
        $siteB1->setRegion($regionB)->setActif(true);

        // --- Permissions reporting.* (redondant avec la migration, nécessaire car les tests
        // recréent le schéma en base de test, cf. `ReportingApiTestCase`) ---
        $permLire = $this->permissionNommee($manager, 'reporting', 'lire');
        $permPlanifier = $this->permissionNommee($manager, 'reporting', 'planifier');
        $permConfigurer = $this->permissionNommee($manager, 'reporting', 'configurer');

        $roleSite = $this->roleNomme($manager, 'Reporting Directeur Site');
        $roleSite->addPermission($permLire);
        $roleRegion = $this->roleNomme($manager, 'Reporting Directeur Régional');
        $roleRegion->addPermission($permLire)->addPermission($permPlanifier);
        $roleGroupe = $this->roleNomme($manager, 'Reporting Direction Générale');
        $roleGroupe->addPermission($permLire)->addPermission($permPlanifier);
        $roleAdmin = $this->roleNomme($manager, 'Reporting Administrateur');
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
        $this->affectationUnique($manager, $utilisateurSite, $roleSite, $siteA1);
        // Directeur régional : A1 + A2 = Région A ENTIÈRE.
        $this->affectationUnique($manager, $utilisateurRegion, $roleRegion, $siteA1);
        $this->affectationUnique($manager, $utilisateurRegion, $roleRegion, $siteA2);
        // DG : A1 + A2 + B1 = Groupe ENTIER.
        $this->affectationUnique($manager, $utilisateurGroupe, $roleGroupe, $siteA1);
        $this->affectationUnique($manager, $utilisateurGroupe, $roleGroupe, $siteA2);
        $this->affectationUnique($manager, $utilisateurGroupe, $roleGroupe, $siteB1);
        // Administrateur : idem DG + reporting.configurer.
        $this->affectationUnique($manager, $utilisateurAdmin, $roleAdmin, $siteA1);
        $this->affectationUnique($manager, $utilisateurAdmin, $roleAdmin, $siteA2);
        $this->affectationUnique($manager, $utilisateurAdmin, $roleAdmin, $siteB1);
        // Non contigu : A1 + B1 (aucune région/groupe entièrement couverte, cas limite §7 spec).
        $this->affectationUnique($manager, $utilisateurNonContigu, $roleSite, $siteA1);
        $this->affectationUnique($manager, $utilisateurNonContigu, $roleSite, $siteB1);

        // --- Profils exploitant (RG-M6-01, RG-REPORT-09) : A1 régie, A2 DSP, B1 régie. ---
        $this->profilExploitant($manager, '111111111', TypeExploitant::RegieDirecte, $siteA1);
        $this->profilExploitant($manager, '222222222', TypeExploitant::Dsp, $siteA2);
        $this->profilExploitant($manager, '333333333', TypeExploitant::RegieDirecte, $siteB1);

        // ── LE BLOC DE DÉMONSTRATION NE SE POSE QU'UNE FOIS ──────────────────────────────────
        //
        // Tout ce qui suit est un jeu de données cohérent, pas un référentiel : le reposer sur une
        // base qui l'a déjà écraserait ce qui a été corrigé à la main depuis, ou le dupliquerait
        // pour les entités sans contrainte d'unicité — silencieusement.
        //
        // Les permissions et les rôles restent AU-DESSUS de cette garde : ils doivent être rejoués à
        // chaque chargement, sans quoi un droit ajouté au code n'atteindrait jamais une base
        // existante.
        if ($manager->getRepository(Passage::class)->findOneBy([]) !== null) {
            $manager->flush();

            return;
        }

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
        $existant = $manager->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);

        if ($existant instanceof Utilisateur) {
            return $existant->setNom($nom)->setActif(true);
        }

        // Le mot de passe n'est posé qu'à la création : le rejouer écraserait un mot de passe changé.
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom($nom)->setActif(true);
        $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, self::MDP));
        $manager->persist($utilisateur);

        return $utilisateur;
    }

    private function creerVenteEtSession(ObjectManager $manager, Etablissement $etablissement, string $total, Utilisateur $operateur): void
    {
        // Les pieces de vente portent un numero deterministe : si la vente existe deja, tout ce qui
        // suit existe aussi. Sans cette garde, un rechargement empile une seconde vente et un second
        // paiement -- rien ne leve, et le chiffre d'affaires de demonstration double.
        $dejaLa = $manager->getRepository(Vente::class)
            ->findOneBy(['numero' => 'V-' . $etablissement->getNom()]);

        if ($dejaLa instanceof Vente) {
            return;
        }

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
        // Meme raisonnement que pour la vente : l'espace porte un nom deterministe, et tout le reste
        // (acces, controleur, jauge, passages) en decoule. Les passages sont le cas le plus parlant --
        // ils n'ont aucune unicite, donc un rechargement doublerait la frequentation affichee.
        $deja = $manager->getRepository(Espace::class)->findOneBy(['nom' => 'Zone ' . $libellePrefixe]);

        if ($deja instanceof Espace) {
            return;
        }

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
            $axe = $this->parCode($manager, AxeAnalytique::class, $code);
            $axe->setLibelle($libelle)->setType($type)->setEstExtension($estExtension);
            if ($granularites !== null) {
                $axe->setGranularites($granularites);
            }
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
            $indicateur = $this->parCode($manager, Indicateur::class, $code);
            $indicateur->setLibelle($libelle)
                ->setUnite($unite)
                ->setModeCalcul($modeCalcul)
                ->setNature($nature)
                ->setSourceModule($sourceModule)
                ->setSeuilCompletudeMinutes(60);
        }

        $manager->flush();
    }

    /**
     * Un rôle existant plutôt qu'un doublon.
     *
     * `Role.nom` porte une unicité **globale** : recharger les fixtures sur une base qui les a déjà
     * échoue sur « Duplicate entry ». Ce n'est pas théorique — c'est exactement ce qui m'a empêché de
     * régénérer les données de démonstration de la préproduction le 24/08, et qui a fini par me faire
     * effacer les rattachements de droits de trente-quatre rôles.
     *
     * Le harnais de test ne voit jamais ce cas : il recrée le schéma depuis les entités à chaque classe
     * de test, donc les fixtures partent toujours d'une base vide. Les deux mondes ne se croisent pas.
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

    /**
     * **Dix-neuf types construits ici, un seul était gardé.**
     *
     * Le rechargement complet a buté sur `Duplicate entry 'site' for key 'uniq_axe_code'` — les axes
     * analytiques. `claude-G`, qui déroulait la chaîne, l'a relevé ainsi : *« troisième fixture
     * d'affilée où la famille corrigée est celle qui criait. Ce n'est plus une coïncidence, c'est la
     * signature de la méthode "suivre les erreurs". »* Elle avait raison les trois fois.
     *
     * D'où l'inventaire, et non la correction de la ligne 266 : hiérarchie, permissions, onze
     * affectations, profils, utilisateurs, référentiel, ventes de démonstration, topologie d'accès.
     *
     * **Les plus dangereux ne crient pas.** Les `Passage` n'ont aucune unicité : un rechargement
     * doublerait la fréquentation affichée dans le reporting, sans une seule erreur. Idem pour la vente
     * de démonstration, dont le doublement fausserait le chiffre d'affaires — c'est-à-dire exactement
     * les chiffres que ce module existe pour produire. Une fixture de reporting qui se duplique
     * n'abîme pas des données de test : elle **ment sur les indicateurs**.
     *
     * @template T of object
     * @param class-string<T> $classe
     * @return T
     */
    private function parNom(ObjectManager $manager, string $classe, string $nom): object
    {
        $existant = $manager->getRepository($classe)->findOneBy(['nom' => $nom]);

        if ($existant !== null) {
            return $existant;
        }

        $entite = new $classe();
        $entite->setNom($nom);
        $manager->persist($entite);

        return $entite;
    }

    /**
     * @template T of object
     * @param class-string<T> $classe
     * @return T
     */
    private function parCode(ObjectManager $manager, string $classe, string $code): object
    {
        $existant = $manager->getRepository($classe)->findOneBy(['code' => $code]);

        if ($existant !== null) {
            return $existant;
        }

        $entite = new $classe();
        $entite->setCode($code);
        $manager->persist($entite);

        return $entite;
    }

    private function permissionNommee(ObjectManager $manager, string $module, string $action): Permission
    {
        $existante = $manager->getRepository(Permission::class)
            ->findOneBy(['module' => $module, 'action' => $action]);

        if ($existante instanceof Permission) {
            return $existante;
        }

        $permission = (new Permission())->setModule($module)->setAction($action);
        $manager->persist($permission);

        return $permission;
    }

    /**
     * `Affectation` ne porte aucune unicité en base : un rechargement empile des doublons sans lever,
     * et les droits effectifs se calculent en parcourant ces lignes. Trouvé par `claude-G`.
     */
    private function affectationUnique(
        ObjectManager $manager,
        Utilisateur $utilisateur,
        Role $role,
        Etablissement $etablissement,
    ): void {
        $existante = $manager->getRepository(Affectation::class)->findOneBy([
            'utilisateur' => $utilisateur,
            'role' => $role,
            'etablissement' => $etablissement,
        ]);

        if ($existante instanceof Affectation) {
            return;
        }

        $manager->persist(
            (new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etablissement)
        );
    }

    private function profilExploitant(
        ObjectManager $manager,
        string $siren,
        TypeExploitant $type,
        Etablissement $etablissement,
    ): void {
        $existant = $manager->getRepository(ProfilExploitant::class)->findOneBy(['siren' => $siren]);

        if ($existant instanceof ProfilExploitant) {
            return;
        }

        $profil = (new ProfilExploitant())
            ->setType($type)
            ->setSiren($siren)
            ->setEtablissementPrincipal($etablissement);
        $profil->addEtablissementRattache($etablissement);
        $manager->persist($profil);
    }
}
