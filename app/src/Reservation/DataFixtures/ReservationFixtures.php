<?php

declare(strict_types=1);

namespace App\Reservation\DataFixtures;

use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\RegleAnnulation;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Reservation\Enum\ModeMontantAnnulation;
use App\Reservation\Enum\PorteeRegleAnnulation;
use App\Reservation\Enum\StatutCreneau;
use App\Reservation\Enum\StatutReservation;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Sepa\DataFixtures\SepaFixtures;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données du socle M5 (`App\Reservation`) : permissions `reservation.*` + rôles (gestionnaire
 * de planning, agent d'accueil, opérateur de ressource, organisateur/client), quelques Ressources de
 * types variés (terrain, bassin partageable + lignes d'eau, salle, personnel qualifié), des Activités
 * (dont une payante et une gratuite), une `RegleAnnulation` établissement (24 h, facturation
 * `vente_differee_agent`), un Créneau et une Réservation de démonstration.
 */
final class ReservationFixtures extends Fixture implements DependentFixtureInterface
{
    public const RESSOURCE_TERRAIN_LIBELLE = 'Terrain padel n°1';
    public const RESSOURCE_BASSIN_LIBELLE = 'Bassin sportif';
    public const RESSOURCE_LIGNE_1_LIBELLE = 'Ligne 1';
    public const RESSOURCE_LIGNE_2_LIBELLE = 'Ligne 2';
    public const RESSOURCE_SALLE_LIBELLE = 'Salle collective';
    public const RESSOURCE_GUIDE_LIBELLE = 'Guide musée';
    public const ACTIVITE_PADEL_LIBELLE = 'Padel 90 min';
    public const ACTIVITE_GRATUITE_LIBELLE = 'Créneau libre bassin';
    public const ACTIVITE_VISITE_LIBELLE = 'Visite guidée musée';

    public const AGENT_EMAIL = 'agent.reservation@itcotation.com';
    public const AGENT_MDP = 'AgentReservation#2026';
    public const GESTIONNAIRE_EMAIL = 'gestionnaire.planning@itcotation.com';
    public const GESTIONNAIRE_MDP = 'GestionPlanning#2026';
    public const OPERATEUR_EMAIL = 'operateur.ressource@itcotation.com';
    public const OPERATEUR_MDP = 'OperateurRessource#2026';
    public const CLIENT_EMAIL = 'client.organisateur@itcotation.com';
    public const CLIENT_MDP = 'ClientOrganisateur#2026';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function getDependencies(): array
    {
        return [SocleFixtures::class, ComptaFixtures::class, CrmFixtures::class, SepaFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        if (!$etabA instanceof Etablissement) {
            $manager->flush();

            return;
        }

        // --- Permissions reservation.* + octroi complet à l'administrateur (RG-SOCLE-02/03) ---
        $permReservationTout = (new Permission())->setModule('reservation')->setAction('*');
        $manager->persist($permReservationTout);
        $actions = [
            'lire', 'lire_soi', 'gerer_ressource', 'gerer_creneau', 'parametrer_annulation',
            'reserver', 'reserver_soi', 'annuler', 'annuler_soi', 'emarger', 'exonerer', 'forcer',
            'arbitrer_recurrence', 'facturer',
        ];
        $permissions = [];
        foreach ($actions as $action) {
            $permissions[$action] = (new Permission())->setModule('reservation')->setAction($action);
            $manager->persist($permissions[$action]);
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permReservationTout);
        }

        // --- Rôle « Gestionnaire de planning » (§3 spec) ---
        $roleGestionnaire = (new Role())->setNom('Gestionnaire de planning');
        foreach (['lire', 'gerer_ressource', 'gerer_creneau', 'parametrer_annulation', 'arbitrer_recurrence'] as $action) {
            $roleGestionnaire->addPermission($permissions[$action]);
        }
        $manager->persist($roleGestionnaire);
        $gestionnaire = $this->utilisateur($manager, self::GESTIONNAIRE_EMAIL, self::GESTIONNAIRE_MDP, 'Gestionnaire Planning');
        $manager->persist((new Affectation())->setUtilisateur($gestionnaire)->setRole($roleGestionnaire)->setEtablissement($etabA));

        // --- Rôle « Agent d'accueil » (§3 spec) ---
        $roleAgent = (new Role())->setNom('Agent d\'accueil réservation');
        foreach (['lire', 'reserver', 'annuler', 'facturer'] as $action) {
            $roleAgent->addPermission($permissions[$action]);
        }
        $manager->persist($roleAgent);
        $agent = $this->utilisateur($manager, self::AGENT_EMAIL, self::AGENT_MDP, 'Agent Accueil Réservation');
        $manager->persist((new Affectation())->setUtilisateur($agent)->setRole($roleAgent)->setEtablissement($etabA));

        // --- Rôle « Opérateur de ressource » (§3 spec) ---
        $roleOperateur = (new Role())->setNom('Opérateur de ressource');
        foreach (['lire', 'emarger'] as $action) {
            $roleOperateur->addPermission($permissions[$action]);
        }
        $manager->persist($roleOperateur);
        $operateur = $this->utilisateur($manager, self::OPERATEUR_EMAIL, self::OPERATEUR_MDP, 'Opérateur Ressource');
        $manager->persist((new Affectation())->setUtilisateur($operateur)->setRole($roleOperateur)->setEtablissement($etabA));

        // --- Rôle « Client/Organisateur » (§3 spec, lié au client CRM payeur de démonstration) ---
        $roleClient = (new Role())->setNom('Client Organisateur Réservation');
        foreach (['lire_soi', 'reserver_soi', 'annuler_soi'] as $action) {
            $roleClient->addPermission($permissions[$action]);
        }
        $manager->persist($roleClient);
        $payeur = $manager->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        $clientUtilisateur = $this->utilisateur($manager, self::CLIENT_EMAIL, self::CLIENT_MDP, 'Jean Dupont (espace client)');
        if ($payeur instanceof Client) {
            $clientUtilisateur->setClientLie($payeur->getId());
        }
        $manager->persist((new Affectation())->setUtilisateur($clientUtilisateur)->setRole($roleClient)->setEtablissement($etabA));

        // --- Ressources de types variés (RG-M5-03/05/08) ---
        $terrain = (new Ressource())->setEtablissement($etabA)->setCodeType('terrain')
            ->setLibelle(self::RESSOURCE_TERRAIN_LIBELLE)->setCapacitePropre(4);
        $manager->persist($terrain);

        $bassin = (new Ressource())->setEtablissement($etabA)->setCodeType('bassin')
            ->setLibelle(self::RESSOURCE_BASSIN_LIBELLE)->setCapacitePropre(60)->setPartageable(true);
        $manager->persist($bassin);

        $ligne1 = (new Ressource())->setEtablissement($etabA)->setCodeType('ligne_eau')
            ->setLibelle(self::RESSOURCE_LIGNE_1_LIBELLE)->setCapacitePropre(6)->setRessourceMere($bassin);
        $manager->persist($ligne1);

        $ligne2 = (new Ressource())->setEtablissement($etabA)->setCodeType('ligne_eau')
            ->setLibelle(self::RESSOURCE_LIGNE_2_LIBELLE)->setCapacitePropre(6)->setRessourceMere($bassin);
        $manager->persist($ligne2);

        $salle = (new Ressource())->setEtablissement($etabA)->setCodeType('salle')
            ->setLibelle(self::RESSOURCE_SALLE_LIBELLE)->setCapacitePropre(12);
        $manager->persist($salle);

        $guide = (new Ressource())->setEtablissement($etabA)->setCodeType('personnel')
            ->setLibelle(self::RESSOURCE_GUIDE_LIBELLE)->setCapacitePropre(1)->setCompetenceRequise('habilitation_guide');
        $manager->persist($guide);

        // --- Activités (cahier M5-02) : une payante, une gratuite ---
        $activitePadel = (new Activite())->setEtablissement($etabA)->setLibelle(self::ACTIVITE_PADEL_LIBELLE)
            ->setTypeActivite('sport')->setDureeMinutes(90)->setTarifReferenceMontant('24.00');
        $manager->persist($activitePadel);

        $activiteGratuite = (new Activite())->setEtablissement($etabA)->setLibelle(self::ACTIVITE_GRATUITE_LIBELLE)
            ->setTypeActivite('natation')->setDureeMinutes(60)->setTarifReferenceMontant('0.00');
        $manager->persist($activiteGratuite);

        $activiteVisite = (new Activite())->setEtablissement($etabA)->setLibelle(self::ACTIVITE_VISITE_LIBELLE)
            ->setTypeActivite('culture')->setDureeMinutes(60)->setTarifReferenceMontant('12.00')
            ->setCompetenceExigee('habilitation_guide');
        $manager->persist($activiteVisite);

        // --- Règle d'annulation établissement (RG-M5-09) : délai franc 24 h, montant fixe 10 €,
        // facturation « vente différée agent » (mode réellement branché).
        $regle = (new RegleAnnulation())->setEtablissement($etabA)->setPortee(PorteeRegleAnnulation::Etablissement)
            ->setDelaiFrancMinutes(1440)->setModeMontant(ModeMontantAnnulation::Fixe)->setValeurMontant('10.00')
            ->setModeFacturation(ModeFacturationNoShow::VenteDiffereeAgent)->setMargePostCreneauMinutes(0)->setActif(true);
        $manager->persist($regle);

        // --- Créneau + Réservation de démonstration ---
        $debut = (new \DateTimeImmutable('next monday'))->setTime(10, 0);
        $creneauDemo = (new Creneau())->setRessource($terrain)->setActivite($activitePadel)
            ->setDebut($debut)->setFin($debut->modify('+90 minutes'))->setCapacite(4)
            ->setEtablissement($etabA)->setStatut(StatutCreneau::Planifie);
        $manager->persist($creneauDemo);

        if ($payeur instanceof Client) {
            $beneficiairePayeur = $manager->getRepository(Beneficiaire::class)->findOneBy(['client' => $payeur]);
            if ($beneficiairePayeur instanceof Beneficiaire) {
                $reservationDemo = (new Reservation())->setCreneau($creneauDemo)->setOrganisateur($beneficiairePayeur)
                    ->setEtablissement($etabA)->setModeDecompte(ModeDecompteReservation::Gratuit)->setMontantDu('0.00')
                    ->setStatut(StatutReservation::Confirmee)
                    ->setDateLimiteAnnulation($debut->modify('-1440 minutes'));
                $manager->persist($reservationDemo);
            }
        }

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
