<?php

declare(strict_types=1);

namespace App\Padel\DataFixtures;

use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Entity\Famille;
use App\Crm\Enum\RoleBeneficiaire;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Padel\Entity\GrilleTarifaireTerrain;
use App\Padel\Entity\NiveauJoueur;
use App\Padel\Entity\ParametragePadel;
use App\Padel\Entity\PlageHoraire;
use App\Padel\Entity\RelaisEclairageTerrain;
use App\Padel\Entity\ReservationPadel;
use App\Padel\Entity\TerrainPadel;
use App\Padel\Entity\Tournoi;
use App\Padel\Enum\FormatTournoi;
use App\Padel\Enum\LibellePlageHoraire;
use App\Padel\Enum\StatutJoueurTarif;
use App\Padel\Enum\StatutNiveauJoueur;
use App\Padel\Enum\StatutPartieOuverte;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\ParticipantReservation;
use App\Reservation\Entity\RegleAnnulation;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Reservation\Enum\ModeMontantAnnulation;
use App\Reservation\Enum\PorteeRegleAnnulation;
use App\Reservation\Enum\StatutCreneau;
use App\Reservation\Enum\StatutPaiementParticipant;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données de la verticale Padel : permissions `padel.*` + rôles (Gestionnaire de club, Joueur),
 * 8 joueurs de démonstration, 1 terrain (+ ressource socle, plages horaires pleine/creuse, grille
 * tarifaire, relais d'éclairage, règle d'annulation dédiée), 1 partie ouverte à 2 joueurs, 1 tournoi
 * démo avec 2 paires inscrites et payées (US-PADEL-01..10).
 */
final class PadelFixtures extends Fixture implements DependentFixtureInterface
{
    public const GESTIONNAIRE_EMAIL = 'gestionnaire.padel@itcotation.com';
    public const GESTIONNAIRE_MDP = 'aaa';
    public const JOUEUR_EMAIL_PREFIX = 'joueur';
    public const JOUEUR_DOMAINE = '@padel.test';
    public const NB_JOUEURS = 8;
    public const TERRAIN_LIBELLE = 'Terrain padel n°1 (indoor)';
    public const TOURNOI_NOM = 'Tournoi de rentrée';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function getDependencies(): array
    {
        return [SocleFixtures::class, \App\Crm\DataFixtures\CrmFixtures::class, \App\Reservation\DataFixtures\ReservationFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        if (!$etabA instanceof Etablissement) {
            $manager->flush();

            return;
        }
        $groupeA = $etabA->getRegion()?->getGroupe();

        // --- Permissions padel.* + octroi complet à l'administrateur (RG-SOCLE-02/03) ---
        $permPadelTout = $this->permissionPour($manager, 'padel', '*');
        $manager->persist($permPadelTout);
        $actions = [
            'lire', 'lire_soi', 'reserver', 'reserver_soi', 'partie_rejoindre_soi',
            'niveau_declarer_soi', 'niveau_valider', 'tournoi_gerer', 'tournoi_inscrire_soi',
            'materiel_gerer', 'acces_forcer', 'gerer_terrain', 'configurer_eclairage', 'parametrer',
            'coach_lire_soi',
        ];
        $permissions = [];
        foreach ($actions as $action) {
            $permissions[$action] = $this->permissionPour($manager, 'padel', $action);
            $manager->persist($permissions[$action]);
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permPadelTout);
        }

        // --- Rôle « Gestionnaire de club » (§3 spec-padel.md) ---
        $roleGestionnaire = $this->roleNomme($manager, 'Gestionnaire de club padel');
        foreach (['lire', 'reserver', 'niveau_valider', 'tournoi_gerer', 'materiel_gerer', 'acces_forcer', 'gerer_terrain', 'configurer_eclairage', 'parametrer'] as $action) {
            $roleGestionnaire->addPermission($permissions[$action]);
        }
        $manager->persist($roleGestionnaire);
        $gestionnaire = $this->utilisateur($manager, self::GESTIONNAIRE_EMAIL, self::GESTIONNAIRE_MDP, 'Gestionnaire Club Padel');
        $manager->persist((new Affectation())->setUtilisateur($gestionnaire)->setRole($roleGestionnaire)->setEtablissement($etabA));

        // --- Rôle « Joueur / Adhérent » (§3 spec-padel.md) ---
        $roleJoueur = $this->roleNomme($manager, 'Joueur Padel');
        foreach (['lire_soi', 'reserver_soi', 'partie_rejoindre_soi', 'niveau_declarer_soi', 'tournoi_inscrire_soi', 'coach_lire_soi'] as $action) {
            $roleJoueur->addPermission($permissions[$action]);
        }
        $manager->persist($roleJoueur);

        if (!$groupeA instanceof Groupe) {
            $manager->flush();

            return;
        }

        // --- 8 joueurs de démonstration (Client + Beneficiaire + Utilisateur, rôle Joueur Padel) ---
        $famille = (new Famille())->setGroupe($groupeA)->setLibelle('Famille Padel Démo');
        $manager->persist($famille);

        /** @var list<Beneficiaire> $joueurs */
        $joueurs = [];
        for ($i = 1; $i <= self::NB_JOUEURS; ++$i) {
            $client = (new Client())
                ->setType(TypeClient::Physique)
                ->setGroupe($groupeA)
                ->setEtablissementCreation($etabA)
                ->setNom('Joueur')
                ->setPrenom('Padel ' . $i)
                ->setEmail(self::JOUEUR_EMAIL_PREFIX . $i . self::JOUEUR_DOMAINE)
                ->setDateNaissance(new \DateTimeImmutable('-30 years'));
            $manager->persist($client);
            if ($i === 1) {
                $famille->setPayeurPrincipal($client);
            }

            $beneficiaire = (new Beneficiaire())->setFamille($famille)->setClient($client)
                ->setRole($i === 1 ? RoleBeneficiaire::Payeur : RoleBeneficiaire::Beneficiaire)
                ->setAutorisations(['entree_seule']);
            $famille->addBeneficiaire($beneficiaire);
            $manager->persist($beneficiaire);
            $joueurs[] = $beneficiaire;

            $utilisateur = $this->utilisateur($manager, self::JOUEUR_EMAIL_PREFIX . $i . self::JOUEUR_DOMAINE, 'JoueurPadel#2026', 'Joueur Padel ' . $i);
            $utilisateur->setClientLie($client->getId());
            $manager->persist($utilisateur);
            $manager->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($roleJoueur)->setEtablissement($etabA));
        }

        // --- Terrain padel n°1 (+ Ressource socle) ---
        $ressourceTerrain = (new Ressource())->setEtablissement($etabA)->setCodeType('terrain_padel')
            ->setLibelle(self::TERRAIN_LIBELLE)->setCapacitePropre(4)->setOuvreAcces(true);
        $manager->persist($ressourceTerrain);

        $terrain = (new TerrainPadel())->setRessource($ressourceTerrain)
            ->setType(\App\Padel\Enum\TypeTerrain::Indoor)->setDureesAutoriseesMinutes([60, 90]);
        $manager->persist($terrain);

        // --- Coach (ressource socle nue, décision structurante n°1 du plan) ---
        $ressourceCoach = (new Ressource())->setEtablissement($etabA)->setCodeType('coach_padel')
            ->setLibelle('Coach padel démo')->setCapacitePropre(1)->setCompetenceRequise('coach_padel');
        $manager->persist($ressourceCoach);

        // --- Paramétrage établissement (échelle 1-10, répartition équitable, tolérance badge 15 min) ---
        $parametrage = (new ParametragePadel())->setEtablissement($etabA)
            ->setEchelleNiveauMin(1)->setEchelleNiveauMax(10)
            ->setModeRepartitionSurcout(\App\Padel\Enum\ModeRepartitionSurcout::EquitablePresents)
            ->setToleranceEntreeBadgeMinutes(15)->setMajorationCoachMontant('15.00');
        $manager->persist($parametrage);

        // --- Plages horaires pleine (18h-23h en semaine) / creuse (le reste) ---
        $plagePleine = (new PlageHoraire())->setEtablissement($etabA)->setLibelle(LibellePlageHoraire::Pleine)
            ->setHeureDebut(new \DateTimeImmutable('18:00:00'))->setHeureFin(new \DateTimeImmutable('23:00:00'))
            ->setJoursApplicables([1, 2, 3, 4, 5]);
        $manager->persist($plagePleine);

        $plageCreuse1 = (new PlageHoraire())->setEtablissement($etabA)->setLibelle(LibellePlageHoraire::Creuse)
            ->setHeureDebut(new \DateTimeImmutable('00:00:00'))->setHeureFin(new \DateTimeImmutable('18:00:00'))
            ->setJoursApplicables([1, 2, 3, 4, 5]);
        $manager->persist($plageCreuse1);

        $plageCreuse2 = (new PlageHoraire())->setEtablissement($etabA)->setLibelle(LibellePlageHoraire::Creuse)
            ->setHeureDebut(new \DateTimeImmutable('23:00:00'))->setHeureFin(new \DateTimeImmutable('23:59:59'))
            ->setJoursApplicables([1, 2, 3, 4, 5]);
        $manager->persist($plageCreuse2);

        $plageWeekend = (new PlageHoraire())->setEtablissement($etabA)->setLibelle(LibellePlageHoraire::Pleine)
            ->setHeureDebut(new \DateTimeImmutable('00:00:00'))->setHeureFin(new \DateTimeImmutable('23:59:59'))
            ->setJoursApplicables([6, 7]);
        $manager->persist($plageWeekend);

        // --- Grille tarifaire (RG-PADEL-02) : pleine/creuse × membre/non-membre × 60/90 min ---
        $tarifs = [
            [$plagePleine, StatutJoueurTarif::NonMembre, 60, '28.00'],
            [$plagePleine, StatutJoueurTarif::NonMembre, 90, '38.00'],
            [$plagePleine, StatutJoueurTarif::Membre, 60, '20.00'],
            [$plagePleine, StatutJoueurTarif::Membre, 90, '28.00'],
            [$plageCreuse1, StatutJoueurTarif::NonMembre, 60, '20.00'],
            [$plageCreuse1, StatutJoueurTarif::NonMembre, 90, '28.00'],
            [$plageCreuse1, StatutJoueurTarif::Membre, 60, '14.00'],
            [$plageCreuse1, StatutJoueurTarif::Membre, 90, '20.00'],
            [$plageWeekend, StatutJoueurTarif::NonMembre, 60, '28.00'],
            [$plageWeekend, StatutJoueurTarif::NonMembre, 90, '38.00'],
            [$plageWeekend, StatutJoueurTarif::Membre, 60, '20.00'],
            [$plageWeekend, StatutJoueurTarif::Membre, 90, '28.00'],
        ];
        foreach ($tarifs as [$plage, $statut, $duree, $prix]) {
            $grille = (new GrilleTarifaireTerrain())->setTerrain($terrain)->setPlageHoraire($plage)
                ->setStatutJoueur($statut)->setDureeMinutes($duree)->setPrix($prix);
            $manager->persist($grille);
        }

        // --- Relais d'éclairage (RG-PADEL-05) ---
        $relais = (new RelaisEclairageTerrain())->setTerrain($terrain)->setIdentifiantRelais('RELAIS-TERRAIN-1');
        $manager->persist($relais);

        // --- Règle d'annulation dédiée padel (RG-PADEL-06, décision actée « délai franc 24h ») ---
        $regleAnnulation = (new RegleAnnulation())->setEtablissement($etabA)
            ->setPortee(PorteeRegleAnnulation::TypeRessource)->setCibleTypeRessource('terrain_padel')
            ->setDelaiFrancMinutes(1440)->setModeMontant(ModeMontantAnnulation::Fixe)->setValeurMontant('15.00')
            ->setModeFacturation(ModeFacturationNoShow::VenteDiffereeAgent)
            ->setExonerations([['motif' => 'membre', 'condition' => 'statutJoueur=membre']])
            ->setActif(true);
        $manager->persist($regleAnnulation);

        // --- Niveaux de jeu : joueur 1 validé (niveau 5), joueur 2 proposé non validé (CA-5) ---
        $admin = $manager->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        $niveau1 = (new NiveauJoueur())->setJoueur($joueurs[0])->setEtablissement($etabA)->setNiveau(5)
            ->setStatut(StatutNiveauJoueur::Valide)->setDateValidation(new \DateTimeImmutable());
        if ($admin instanceof Utilisateur) {
            $niveau1->setValideParUtilisateur($admin);
        }
        $manager->persist($niveau1);

        $niveau2 = (new NiveauJoueur())->setJoueur($joueurs[1])->setEtablissement($etabA)->setNiveau(5)
            ->setStatut(StatutNiveauJoueur::Propose);
        $manager->persist($niveau2);

        // --- Partie ouverte de démonstration (2 joueurs, US-PADEL-02/03) ---
        // Créneau matinal (heure creuse), placé une semaine APRÈS les scénarios de test.
        //
        // ⚠ L'ancre précédente était « next tuesday » : distincte du « next monday » des tests par le
        // jour de la semaine, mais pas dans le TEMPS. Lancée un dimanche ou un lundi, « next tuesday »
        // tombe AVANT « next monday » — la réservation de démonstration devenait alors antérieure à
        // l'horloge simulée par les tests, et `CommanderEclairageCommand`, qui balaie toutes les
        // réservations padel sans borne de date, y déclenchait un allumage ET une extinction.
        // `EclairageTest` comptait trois commandes au lieu d'une, deux jours par semaine, sans qu'une
        // seule ligne de code ait changé. Ce qui compte n'est pas le jour, c'est d'être APRÈS
        // (D20 : aucune assertion d'horloge dans la suite fonctionnelle).
        $debutDemo = (new \DateTimeImmutable('next monday'))->modify('+1 week')->setTime(9, 0);
        $creneauDemo = (new Creneau())->setRessource($ressourceTerrain)->setDebut($debutDemo)
            ->setFin($debutDemo->modify('+90 minutes'))->setCapacite(4)->setEtablissement($etabA)
            ->setStatut(StatutCreneau::Planifie);
        $manager->persist($creneauDemo);

        $reservationDemo = (new Reservation())->setCreneau($creneauDemo)->setOrganisateur($joueurs[0])
            ->setEtablissement($etabA)->setModeDecompte(ModeDecompteReservation::VenteUnite)->setMontantDu('28.00');
        $manager->persist($reservationDemo);

        $participant1 = (new ParticipantReservation())->setPersonne($joueurs[0])->setEstOrganisateur(true)
            ->setPartMontant('7.00')->setStatutPaiement(StatutPaiementParticipant::Paye);
        $reservationDemo->addParticipant($participant1);
        $manager->persist($participant1);

        $participant2 = (new ParticipantReservation())->setPersonne($joueurs[2])->setEstOrganisateur(false)
            ->setPartMontant('7.00')->setStatutPaiement(StatutPaiementParticipant::Paye);
        $reservationDemo->addParticipant($participant2);
        $manager->persist($participant2);

        $reservationPadelDemo = (new ReservationPadel())->setReservation($reservationDemo)->setTerrain($terrain)
            ->setOuverte(true)->setNiveauViseMin(3)->setNiveauViseMax(8)->setStatutPartie(StatutPartieOuverte::Ouverte);
        $manager->persist($reservationPadelDemo);

        // --- Tournoi de démonstration (2 paires inscrites et payées, US-PADEL-05) ---
        $tournoi = (new Tournoi())->setEtablissement($etabA)->setNom(self::TOURNOI_NOM)
            ->setFormat(FormatTournoi::Poules)->setCategorie('Mixte')
            ->setFraisInscription('20.00')
            ->setDateDebut(new \DateTimeImmutable('+2 weeks'))->setDateFin(new \DateTimeImmutable('+2 weeks +1 day'));
        $manager->persist($tournoi);

        $inscription1 = (new \App\Padel\Entity\InscriptionTournoi())->setTournoi($tournoi)
            ->setJoueur1($joueurs[0])->setJoueur2($joueurs[1])
            ->setStatutPaiement(\App\Padel\Enum\StatutPaiementInscriptionTournoi::Paye);
        $manager->persist($inscription1);

        $inscription2 = (new \App\Padel\Entity\InscriptionTournoi())->setTournoi($tournoi)
            ->setJoueur1($joueurs[2])->setJoueur2($joueurs[3])
            ->setStatutPaiement(\App\Padel\Enum\StatutPaiementInscriptionTournoi::Paye);
        $manager->persist($inscription2);

        $manager->flush();
    }

    private function utilisateur(ObjectManager $manager, string $email, string $motDePasse, string $nom): Utilisateur
    {
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

    /**
     * Meme raison que pour les roles : le couple (module, action) porte lui aussi une unicite.
     *
     * Rappeler `persist()` sur l'entite rendue est sans effet — Doctrine ignore un objet deja gere —
     * ce qui permet de laisser en place les appels existants plutot que de les demeler un a un.
     */
    private function permissionPour(ObjectManager $manager, string $module, string $action): Permission
    {
        $existante = $manager->getRepository(Permission::class)->findOneBy(['module' => $module, 'action' => $action]);

        if ($existante instanceof Permission) {
            return $existante;
        }

        $permission = (new Permission())->setModule($module)->setAction($action);
        $manager->persist($permission);

        return $permission;
    }
}
