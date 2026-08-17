<?php

declare(strict_types=1);

namespace App\Acces\DataFixtures;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\JetonTerminal;
use App\Acces\Entity\Support;
use App\Acces\Entity\Terminal;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\ModeSeuil;
use App\Acces\Enum\SensEquipement;
use App\Acces\Enum\StatutJetonTerminal;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\StatutTerminal;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeEquipement;
use App\Acces\Enum\TypeSupport;
use App\Acces\Service\VersionSnapshotSequencer;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données L3 (US-L3-*) : permissions acces.* accordées à l'administrateur, 1 espace d'accès +
 * 1 contrôleur + 1 tourniquet d'entrée sur l'établissement A, 1 support QR appairé à un droit projeté
 * depuis la carte multi-entrées « Carte 10=12 piscine » (M1, RG-M1-04/13).
 */
final class AccesFixtures extends Fixture implements DependentFixtureInterface
{
    public const ESPACE_LIBELLE = 'Zone tourniquets Piscine A';
    public const CONTROLEUR_LIBELLE = 'Contrôleur Entrée A1';
    public const EQUIPEMENT_LIBELLE = 'Tourniquet Entrée A1';
    public const ITBOX_REF = 'ITBOX-A1';
    public const SUPPORT_IDENTIFIANT = 'QR-DEMO-0001';
    public const SEUIL_FMI = 50;
    public const TERMINAL_NOM = 'ITBOX Démo Entrée A1';
    /** Secret en clair du jeton terminal de démonstration (tests, plan-acces-terminal.md §7 Lot E T19). */
    public const TERMINAL_SECRET = 'demo-terminal-secret-0001-do-not-use-in-prod';

    public function __construct(
        private readonly VersionSnapshotSequencer $sequencer,
    ) {
    }

    public function getDependencies(): array
    {
        return [SocleFixtures::class, OffreFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // Séquence native MariaDB du curseur de snapshot terminal (US-TERM-03/04, plan-acces-terminal.md
        // §1.4). `AccesApiTestCase` reconstruit le schéma via `SchemaTool` (métadonnées ORM), qui
        // n'inclut pas les objets créés en base par une migration `CREATE SEQUENCE` (hors mapping
        // Doctrine) : recréée ici de façon idempotente pour que les tests disposent de la séquence
        // sans dépendre du rejeu des migrations.
        $manager->getConnection()->executeStatement('CREATE SEQUENCE IF NOT EXISTS acces_snapshot_seq START WITH 1 INCREMENT BY 1');

        // --- Permissions acces.* + octroi à l'administrateur (RG-SOCLE-02/03) ---
        $perms = [];
        foreach (['gerer', 'lire', 'superviser', 'appairer', 'ouvrir_manuel', 'controler', 'bloquer_support', 'ingestion', 'snapshot'] as $action) {
            $perm = (new Permission())->setModule('acces')->setAction($action);
            $manager->persist($perm);
            $perms[$action] = $perm;
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            foreach ($perms as $perm) {
                $roleAdmin->addPermission($perm);
            }
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        if (!$etabA instanceof Etablissement) {
            $manager->flush();

            return;
        }

        // --- Topologie : 1 espace d'accès + 1 contrôleur + 1 tourniquet d'entrée (établissement A) ---
        $espaceSocle = (new Espace())->setNom('Bassin principal')->setEtablissement($etabA)->setType('bassin');
        $manager->persist($espaceSocle);

        $espaceAcces = (new EspaceAcces())
            ->setLibelle(self::ESPACE_LIBELLE)
            ->setEspaceSocle($espaceSocle)
            ->setSeuilFmi(self::SEUIL_FMI)
            ->setModeSeuil(ModeSeuil::Blocage);
        $manager->persist($espaceAcces);

        $controleur = (new Controleur())
            ->setLibelle(self::CONTROLEUR_LIBELLE)
            ->setEspace($espaceAcces)
            ->setItboxRef(self::ITBOX_REF);
        $manager->persist($controleur);

        $equipement = (new Equipement())
            ->setLibelle(self::EQUIPEMENT_LIBELLE)
            ->setControleur($controleur)
            ->setType(TypeEquipement::Tourniquet)
            ->setSens(SensEquipement::Entree);
        $manager->persist($equipement);

        // --- Droit projeté depuis la carte multi-entrées M1 « Carte 10=12 piscine » ---
        $carteProduit = $manager->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_CARTE]);

        $droit = new DroitAcces();
        $droit->setSourceType(TypeDroitAcces::CarteQuota)
            ->setStatutProjection(StatutProjectionDroit::Valide)
            ->setEtablissement($etabA)
            ->setSynchroniseLe(new \DateTimeImmutable());
        if ($carteProduit instanceof Produit) {
            $droit->setProduitRef($carteProduit->getId());
            $droit->setCreditRestant($carteProduit->getCarte()?->getStockCompostagesInitial() ?? 12);
        } else {
            $droit->setCreditRestant(12);
        }
        $manager->persist($droit);

        // --- Support QR appairé au droit ci-dessus (mode caisse, US-L3-02) ---
        $support = (new Support())
            ->setIdentifiant(self::SUPPORT_IDENTIFIANT)
            ->setType(TypeSupport::Qr)
            ->setEtablissement($etabA);
        $manager->persist($support);

        $appairage = (new Appairage())
            ->setSupport($support)
            ->setDroit($droit)
            ->setMode(ModeAppairage::Caisse)
            ->setActif(true)
            ->setEtablissement($etabA);
        $manager->persist($appairage);
        // Reflète ce qu'un appairage réel produirait via App\Acces\Service\AppairageHandler (bypassée
        // ici, fixture = persistance directe) : un curseur de version réel (US-TERM-03/04, §1.4 du plan).
        $support->setVersionMaj($this->sequencer->suivant());

        // --- Terminal de démonstration (US-TERM-01/09, plan-acces-terminal.md §7 Lot E T19) : couvre
        // la portée de l'ITBOX de la fixture ci-dessus, jeton actif de secret connu (tests). ---
        $terminal = (new Terminal())
            ->setNom(self::TERMINAL_NOM)
            ->setItboxRef(self::ITBOX_REF)
            ->setEtablissement($etabA)
            ->setStatut(StatutTerminal::Actif);
        $manager->persist($terminal);

        $jetonTerminal = (new JetonTerminal())
            ->setTerminal($terminal)
            ->setSecretHash(hash('sha256', self::TERMINAL_SECRET))
            ->setStatut(StatutJetonTerminal::Actif)
            ->setEtablissement($etabA);
        $manager->persist($jetonTerminal);

        $manager->flush();
    }
}
