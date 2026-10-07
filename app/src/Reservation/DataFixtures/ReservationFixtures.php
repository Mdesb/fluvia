<?php

declare(strict_types=1);

namespace App\Reservation\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
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
    use FixturesIdempotentes;

    public const RESSOURCE_TERRAIN_LIBELLE = 'Terrain padel n°1';
    /**
     * Le terrain de la DÉMONSTRATION, distinct de celui des tests.
     *
     * ⚠ Ne l'utilise dans aucun test : son créneau est posé à une date relative (« lundi
     * prochain »), donc ce qu'il occupe change toutes les semaines. C'est précisément le mélange
     * qui a fait tomber trois tests en cascade — voir le commentaire au point de création.
     */
    public const RESSOURCE_TERRAIN_DEMO_LIBELLE = 'Terrain padel n°2 (démonstration)';
    public const RESSOURCE_BASSIN_LIBELLE = 'Bassin sportif';
    public const RESSOURCE_LIGNE_1_LIBELLE = 'Ligne 1';
    public const RESSOURCE_LIGNE_2_LIBELLE = 'Ligne 2';
    public const RESSOURCE_SALLE_LIBELLE = 'Salle collective';
    public const RESSOURCE_GUIDE_LIBELLE = 'Guide musée';
    public const ACTIVITE_PADEL_LIBELLE = 'Padel 90 min';
    public const ACTIVITE_GRATUITE_LIBELLE = 'Créneau libre bassin';
    public const ACTIVITE_VISITE_LIBELLE = 'Visite guidée musée';

    public const AGENT_EMAIL = 'agent.reservation@itcotation.com';
    public const AGENT_MDP = 'aaa';
    public const GESTIONNAIRE_EMAIL = 'gestionnaire.planning@itcotation.com';
    public const GESTIONNAIRE_MDP = 'aaa';
    public const OPERATEUR_EMAIL = 'operateur.ressource@itcotation.com';
    public const OPERATEUR_MDP = 'aaa';
    public const CLIENT_EMAIL = 'client.organisateur@itcotation.com';
    public const CLIENT_MDP = 'aaa';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function getDependencies(): array
    {
        // ⚠ `OffreFixtures` AJOUTEE LE 04/09 : les activites portent desormais un produit
        // tarifaire, et sans cette dependance les produits pouvaient ne pas exister encore.
        // Aucun cycle : `OffreFixtures` ne depend que de `SocleFixtures`.
        return [SocleFixtures::class, ComptaFixtures::class, CrmFixtures::class, SepaFixtures::class, OffreFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        if (!$etabA instanceof Etablissement) {
            $manager->flush();

            return;
        }

        // --- Permissions reservation.* + octroi complet à l'administrateur (RG-SOCLE-02/03) ---
        $permReservationTout = $this->permission($manager, 'reservation', '*');
        $actions = [
            'lire', 'lire_soi', 'gerer_ressource', 'gerer_creneau', 'parametrer_annulation',
            'reserver', 'reserver_soi', 'annuler', 'annuler_soi', 'emarger', 'exonerer', 'forcer',
            'arbitrer_recurrence', 'facturer', 'lever_absence',
        ];
        $permissions = [];
        foreach ($actions as $action) {
            $permissions[$action] = $this->permission($manager, 'reservation', $action);
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permReservationTout);
        }

        // --- Rôle « Gestionnaire de planning » (§3 spec) ---
        $roleGestionnaire = $this->roleNomme($manager, 'Gestionnaire de planning');
        foreach (['lire', 'gerer_ressource', 'gerer_creneau', 'parametrer_annulation', 'arbitrer_recurrence'] as $action) {
            $roleGestionnaire->addPermission($permissions[$action]);
        }
        $manager->persist($roleGestionnaire);
        $gestionnaire = $this->utilisateur($manager, self::GESTIONNAIRE_EMAIL, self::GESTIONNAIRE_MDP, 'Gestionnaire Planning');
        $this->affectation($manager, $gestionnaire, $roleGestionnaire, $etabA);

        // --- Rôle « Agent d'accueil » (§3 spec) ---
        $roleAgent = $this->roleNomme($manager, 'Agent d\'accueil réservation');
        foreach (['lire', 'reserver', 'annuler', 'facturer'] as $action) {
            $roleAgent->addPermission($permissions[$action]);
        }
        $manager->persist($roleAgent);
        $agent = $this->utilisateur($manager, self::AGENT_EMAIL, self::AGENT_MDP, 'Agent Accueil Réservation');
        $this->affectation($manager, $agent, $roleAgent, $etabA);

        // --- Rôle « Opérateur de ressource » (§3 spec) ---
        $roleOperateur = $this->roleNomme($manager, 'Opérateur de ressource');
        foreach (['lire', 'emarger'] as $action) {
            $roleOperateur->addPermission($permissions[$action]);
        }
        $manager->persist($roleOperateur);
        $operateur = $this->utilisateur($manager, self::OPERATEUR_EMAIL, self::OPERATEUR_MDP, 'Opérateur Ressource');
        $this->affectation($manager, $operateur, $roleOperateur, $etabA);

        // --- Rôle « Client/Organisateur » (§3 spec, lié au client CRM payeur de démonstration) ---
        $roleClient = $this->roleNomme($manager, 'Client Organisateur Réservation');
        foreach (['lire_soi', 'reserver_soi', 'annuler_soi'] as $action) {
            $roleClient->addPermission($permissions[$action]);
        }
        $manager->persist($roleClient);
        $payeur = $manager->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        $clientUtilisateur = $this->utilisateur($manager, self::CLIENT_EMAIL, self::CLIENT_MDP, 'Jean Dupont (espace client)');
        if ($payeur instanceof Client) {
            $clientUtilisateur->setClientLie($payeur->getId());
        }
        $this->affectation($manager, $clientUtilisateur, $roleClient, $etabA);

        // --- Ressources de types variés (RG-M5-03/05/08) ---
        // ── LE BLOC DE DEMONSTRATION NE SE POSE QU'UNE FOIS ──────────────────────────────────
        //
        // `reservation_ressource` NE PORTE AUCUNE CONTRAINTE D'UNICITE : un second chargement n'y
        // echoue pas, il double les six ressources en silence. C'est le cas que seul le comptage de
        // lignes attrape -- et celui qui fausse le plus de choses en aval, puisque les creneaux et
        // les reservations s'y rattachent.
        //
        // La sentinelle est la premiere ressource de l'etablissement A : elle est creee juste apres,
        // inconditionnellement.
        if ($manager->getRepository(Ressource::class)
            ->findOneBy(['etablissement' => $etabA, 'codeType' => 'terrain']) !== null
        ) {
            $manager->flush();

            return;
        }

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

        // ⚠ LE PRODUIT TARIFAIRE EST OBLIGATOIRE DEPUIS LE 04/09 sur toute activité PAYANTE :
        // sans lui, `ReserverProcessor` refuse la vente plutôt que d'inventer un produit inexistant.
        // On le résout par son libellé, comme le reste de ces fixtures.
        // ⚠ ON CHERCHE PAR `libelleRecherche`, PAS PAR `libelle`. `Produit::$libelle` est un TABLEAU
        // (`['fr' => '...']`) : un `findOneBy(['libelle' => 'Entrée unitaire piscine'])` compare une
        // chaine a une colonne JSON, ne trouve rien, et rend `null` EN SILENCE — puis le `?->` de
        // l'activite avale le nul, et la garde du produit obligatoire refuse la vente. C'est le
        // defaut meme contre lequel cette garde a ete ecrite, reproduit une fonction plus loin.
        $produitEntree = $manager->getRepository(Produit::class)
            ->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);
        if ($produitEntree === null) {
            // ⚠ ON NE CONTINUE PAS SANS. Poser une activite payante sans produit recree exactement
            // l'etat que la garde refuse, et le prochain le decouvrirait par 33 tests rouges.
            throw new \RuntimeException(
                'Fixture : produit « ' . OffreFixtures::PRODUIT_ENTREE . ' » introuvable. '
                . 'Les activites payantes ne peuvent pas etre creees sans produit tarifaire.',
            );
        }

        // --- Activités (cahier M5-02) : une payante, une gratuite ---
        $activitePadel = (new Activite())->setEtablissement($etabA)->setLibelle(self::ACTIVITE_PADEL_LIBELLE)
            ->setTypeActivite('sport')->setDureeMinutes(90)->setTarifReferenceMontant('24.00')
            ->setProduitTarifReference($produitEntree);
        $manager->persist($activitePadel);

        $activiteGratuite = (new Activite())->setEtablissement($etabA)->setLibelle(self::ACTIVITE_GRATUITE_LIBELLE)
            ->setTypeActivite('natation')->setDureeMinutes(60)->setTarifReferenceMontant('0.00');
        $manager->persist($activiteGratuite);

        $activiteVisite = (new Activite())->setEtablissement($etabA)->setLibelle(self::ACTIVITE_VISITE_LIBELLE)
            ->setTypeActivite('culture')->setDureeMinutes(60)->setTarifReferenceMontant('12.00')
            ->setCompetenceExigee('habilitation_guide')
            ->setProduitTarifReference($produitEntree);
        $manager->persist($activiteVisite);

        // --- Règle d'annulation établissement (RG-M5-09) : délai franc 24 h, montant fixe 10 €,
        // facturation « vente différée agent » (mode réellement branché).
        $regle = (new RegleAnnulation())->setEtablissement($etabA)->setPortee(PorteeRegleAnnulation::Etablissement)
            ->setDelaiFrancMinutes(1440)->setModeMontant(ModeMontantAnnulation::Fixe)->setValeurMontant('10.00')
            ->setModeFacturation(ModeFacturationNoShow::VenteDiffereeAgent)->setMargePostCreneauMinutes(0)->setActif(true);
        $manager->persist($regle);

        // --- Créneau + Réservation de démonstration ---
        //
        // ⚠ SUR SA PROPRE RESSOURCE, ET C'EST LE CORRECTIF D'UN DÉFAUT DATÉ (07/09).
        //
        // Ce créneau était posé sur `$terrain` — la ressource que dix fichiers de test utilisent —
        // à une date RELATIVE (`next monday`), pendant que ces tests y créent des créneaux à des
        // dates ABSOLUES. Les deux se sont rencontrés : le 07/09/2026 étant un lundi,
        // « next monday » valait 2026-09-14 10:00 → 11:30, exactement la fenêtre de
        // `RecurrenceTest` et de `OccurrenceEnConflitTest`. `ChevauchementCreneauGuard` faisait son
        // travail, l'API rendait 409, et les deux tests tombaient.
        //
        // ⚠ CE QUI REND CE DÉFAUT MÉCHANT N'EST PAS QU'IL CASSE, C'EST QU'IL SE DÉPLACE ET GUÉRIT.
        // Projeté : collision les semaines du 07/09, du 14/09 et du 21/09 — en changeant de test
        // chaque semaine, `AnnulationNoShowTest` (21/09) étant le suivant sur la liste — puis plus
        // rien à partir du 28/09, sans que personne n'ait rien corrigé. Trois semaines de rouge
        // errant suivies d'une guérison spontanée, c'est le portrait exact d'un test qu'on finit
        // par déclarer « instable » et désactiver. On aurait perdu `OccurrenceEnConflitTest`, qui
        // garde la décision de Maxime du 31/08 (« ne jamais déplacer tout seul »).
        //
        // La ressource dédiée coupe la racine plutôt que la branche : décaler la date de la
        // démonstration, ou celles des trois tests, n'aurait fait que replanter la même mine sur
        // une autre case du calendrier. Aucun test ne référence ce libellé — vérifié — et aucun ne
        // compte les ressources, donc rien ne dépend de ce qu'on ajoute ici.
        //
        // ⚠ ET ON NE POSE PLUS RIEN SUR `$terrain` DEPUIS UNE FIXTURE. C'est ce que cette ligne
        // protège ; l'y remettre rouvrirait le même défaut, avec les mêmes trois semaines pour le
        // comprendre.
        // ⚠ SON `codeType` EST DISTINCT, ET CE N'EST PAS UN DÉTAIL — je l'ai appris en cassant deux
        // tests verts avec la première version de ce correctif.
        //
        // `RecurrenceReportHandler` cherche une ressource de report par trois critères :
        // `codeType` identique, `capacitePropre >= ` celle du créneau, et `actif`. Une seconde
        // ressource `terrain` de capacité 4 les remplit tous les trois : elle devient une
        // alternative de report pour n'importe quel créneau du terrain de test. Résultat mesuré,
        // pas supposé — `testCa7ReportAutoSurRessourceEquivalente` reportait sur celle-ci au lieu
        // de la sienne, et `testCa7BasculeValidationManuelleSiAucuneAlternative` trouvait
        // justement l'alternative dont il vérifie l'absence. Deux verts perdus pour deux réparés.
        //
        // Aucun code ne compare `codeType` à une valeur littérale — vérifié : les comparaisons sont
        // relatives (`$origin->codeType === $candidate->codeType`). Le distinguer ici est donc sans
        // effet ailleurs, et il dit ce qu'il est.
        $terrainDemo = (new Ressource())->setEtablissement($etabA)->setCodeType('terrain_demo')
            ->setLibelle(self::RESSOURCE_TERRAIN_DEMO_LIBELLE)->setCapacitePropre(4);
        $manager->persist($terrainDemo);

        $debut = (new \DateTimeImmutable('next monday'))->setTime(10, 0);
        $creneauDemo = (new Creneau())->setRessource($terrainDemo)->setActivite($activitePadel)
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

    /**
     * Cherche avant de creer. `Role.nom` porte une unicite **globale** et quatorze fixtures creent
     * des roles : sans cette garde, un chargement complet echoue sur « Duplicate entry ». Le harnais
     * de test ne le voyait pas — il repart d'une base vide a chaque classe. Le seul geste qui revele
     * le defaut est de charger **deux fois**.
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

    /** Le couple (module, action) porte lui aussi une unicite. */
    private function permission(ObjectManager $manager, string $module, string $action): Permission
    {
        $existante = $manager->getRepository(Permission::class)->findOneBy(['module' => $module, 'action' => $action]);
        if ($existante instanceof Permission) {
            return $existante;
        }

        $permission = $this->permissionNommee($manager, $module, $action);
        $manager->persist($permission);

        return $permission;
    }

    /**
     * `Affectation` ne porte pas d'unicite en base : un second chargement ne casserait pas, il
     * **empilerait** des doublons — silencieux, et faux, puisque les droits effectifs se calculent
     * en parcourant les affectations.
     */
    private function affectation(ObjectManager $manager, Utilisateur $utilisateur, Role $role, Etablissement $etablissement): void
    {
        $existante = $manager->getRepository(Affectation::class)->findOneBy([
            'utilisateur' => $utilisateur,
            'role' => $role,
            'etablissement' => $etablissement,
        ]);
        if ($existante instanceof Affectation) {
            return;
        }

        $this->affectationUnique($manager, $utilisateur, $role, $etablissement);
    }

    private function utilisateur(ObjectManager $manager, string $email, string $motDePasse, string $nom): Utilisateur
    {
        $existant = $manager->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        if ($existant instanceof Utilisateur) {
            return $existant;
        }

        $utilisateur = (new Utilisateur())->setEmail($email)->setNom($nom)->setActif(true);
        $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, $motDePasse));
        $manager->persist($utilisateur);

        return $utilisateur;
    }
}
