<?php

declare(strict_types=1);

namespace App\Sport\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\EspaceAcces;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Formule;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Recouvrement\DataFixtures\RecouvrementFixtures;
use App\Recouvrement\Entity\PolitiqueRecouvrement;
use App\Recouvrement\Enum\MomentRefusAcces;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Sport\Entity\ConfigAccesNocturne;
use App\Sport\Entity\StatutAccesFitness;
use App\Sport\Enum\PeriodiciteAbonnementFitness;
use App\Sport\Service\SouscriptionAbonnementHandler;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données de la verticale Sport/Fitness (US-SPORT-*) : permissions `sport.*` accordées à
 * l'administrateur, 1 abonnement fitness avec engagement + mandat SEPA (IBAN tokenisé) sur
 * l'établissement A, rattaché au droit d'accès L3 de démonstration, 1 politique de recouvrement
 * (moteur partagé `App\Recouvrement`, défaut établissement), 1 config d'accès nocturne sur l'espace
 * d'accès L3 de démonstration.
 */
final class SportFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public const ADHERENT_IBAN_DEMO = 'FR7630006000011234567890189';
    public const ADHERENT_TITULAIRE = 'Marie Dupont';
    public const MONTANT_MENSUEL_CENTIMES = 3990;

    public function __construct(
        private readonly SouscriptionAbonnementHandler $souscriptionHandler,
    ) {
    }

    public function getDependencies(): array
    {
        return [SocleFixtures::class, OffreFixtures::class, CrmFixtures::class, AccesFixtures::class, RecouvrementFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // --- Permissions sport.* + octroi à l'administrateur (RG-SOCLE-02/03) ---
        // Le pilotage des impayés (piloter_impayes/forcer_acces/parametrer/resoudre_impaye_soi) a été
        // extrait vers les permissions `recouvrement.*` (RecouvrementFixtures, refactor extraction).
        $perms = [];
        foreach ([
            'gerer_abonnement', 'lire',
            'configurer_nocturne', 'superviser_nocturne', 'lire_soi',
            'pause_demander_soi', 'resilier_demander_soi',
        ] as $action) {
            $perm = $this->permissionNommee($manager, 'sport', $action);
            $manager->persist($perm);
            $perms[$action] = $perm;
        }
        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            foreach ($perms as $perm) {
                $roleAdmin->addPermission($perm);
            }
        }
        // Octroi également au rôle du second groupe (CrmFixtures, RG-SOCLE-05) : permet de tester le
        // cloisonnement établissement avec un utilisateur qui a les permissions mais pas l'affectation.
        $roleAdminB = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe B']);
        if ($roleAdminB instanceof Role) {
            foreach ($perms as $perm) {
                $roleAdminB->addPermission($perm);
            }
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        if (!$etabA instanceof Etablissement) {
            $manager->flush();

            return;
        }

        // ── LE BLOC DE DEMONSTRATION NE SE POSE QU'UNE FOIS ──────────────────────────────────
        //
        // Tout ce qui suit est un jeu de donnees coherent, pas un referentiel : le reposer sur une
        // base qui l'a deja ecraserait ce qui a ete corrige a la main depuis, ou le dupliquerait
        // pour les entites sans contrainte d'unicite -- silencieusement.
        //
        // Les permissions, les roles et les affectations restent AU-DESSUS : ils doivent etre
        // rejoues a chaque chargement, sans quoi un droit ajoute au code n'atteindrait jamais une
        // base existante.
        if ($manager->getRepository(\App\Recouvrement\Entity\PolitiqueRecouvrement::class)->findOneBy([]) !== null) {
            $manager->flush();

            return;
        }
        // --- Politique de recouvrement par défaut (établissement A, moteur partagé App\Recouvrement) ---
        $politique = (new PolitiqueRecouvrement())
            ->setEtablissement($etabA)
            ->setNbRepresentationsMax(1)
            ->setCalendrierRepresentationJours([5])
            ->setMomentRefusAcces(MomentRefusAcces::ApresRepresentationEchouee);
        $manager->persist($politique);
        $manager->flush();

        // --- Config accès nocturne (espace d'accès L3 de démonstration) ---
        $espaceAcces = $manager->getRepository(EspaceAcces::class)->findOneBy(['libelle' => AccesFixtures::ESPACE_LIBELLE]);
        if ($espaceAcces instanceof EspaceAcces) {
            $config = (new ConfigAccesNocturne())
                ->setEspaceAcces($espaceAcces)
                ->setPlageDebut('22:00')
                ->setPlageFin('06:00')
                ->setVideoActive(true)
                ->setBoutonSosActif(true)
                ->setDetectionPresenceIsoleeActive(true)
                ->setLimiteOccupationNocturne(3);
            $manager->persist($config);
            $manager->flush();
        }

        // --- Abonnement fitness de démonstration (bénéficiaire ≠ payeur, RG-M4-02) ---
        $formule = $manager->getRepository(Formule::class)->findOneBy([], ['id' => 'ASC']);
        // Résout précisément la Formule de l'abonnement Gold via le Produit qui la porte.
        $produitGold = $manager->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_GOLD]);
        if ($produitGold instanceof Produit && $produitGold->getFormule() !== null) {
            $formule = $produitGold->getFormule();
        }

        $payeur = $manager->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        $conjoint = $manager->getRepository(Client::class)->findOneBy(['prenom' => CrmFixtures::CONJOINT_PRENOM]);
        $adherent = $conjoint instanceof Client ? $manager->getRepository(Beneficiaire::class)->findOneBy(['client' => $conjoint]) : null;

        if (!$formule instanceof Formule || !$payeur instanceof Client || !$adherent instanceof Beneficiaire) {
            $manager->flush();

            return;
        }

        $abonnement = $this->souscriptionHandler->souscrire(
            $adherent,
            $payeur,
            $formule,
            $etabA,
            PeriodiciteAbonnementFitness::Mensuel,
            new \DateTimeImmutable('-1 month'),
            12,
            self::MONTANT_MENSUEL_CENTIMES,
            self::ADHERENT_IBAN_DEMO,
            self::ADHERENT_TITULAIRE,
        );

        // Rattache immédiatement le droit d'accès L3 de démonstration (§0 point 5 du plan-sport).
        $droit = $manager->getRepository(DroitAcces::class)->findOneBy(['etablissement' => $etabA]);
        if ($droit instanceof DroitAcces) {
            $statutAcces = $manager->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnement]);
            if ($statutAcces instanceof StatutAccesFitness) {
                $statutAcces->setDroitAcces($droit);
            }
        }

        $manager->flush();
    }
}
