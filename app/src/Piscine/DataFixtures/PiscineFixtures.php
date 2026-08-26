<?php

declare(strict_types=1);

namespace App\Piscine\DataFixtures;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\TypeSupport;
use App\Caution\Entity\Caution;
use App\Caution\Enum\StatutCaution as StatutCautionGenerique;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Piscine\Entity\Bassin;
use App\Piscine\Entity\BraceletEtanche;
use App\Piscine\Entity\Casier;
use App\Piscine\Entity\CautionCasier;
use App\Piscine\Entity\LigneEau;
use App\Piscine\Entity\ParametrePiscineEtablissement;
use App\Piscine\Entity\Poss;
use App\Piscine\Entity\QualificationEncadrant;
use App\Piscine\Enum\EtatCasier;
use App\Piscine\Enum\PerimetrePoss;
use App\Piscine\Enum\StatutCaution;
use App\Piscine\Enum\TypeEncadrement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données L6 (US-L6-*) : permissions piscine.* accordées à l'administrateur, 1 `Poss` sur
 * l'espace d'accès L3 de l'établissement A (réutilisé tel quel, aucune duplication), 1 bassin + 4
 * lignes d'eau, 1 casier occupé avec caution active, 1 encadrant MNS à diplôme valide.
 */
final class PiscineFixtures extends Fixture implements DependentFixtureInterface
{
    public const BASSIN_LIBELLE = 'Grand bassin';
    public const CASIER_ZONE = 'Vestiaire A';
    public const CASIER_NUMERO = 1;
    public const BRACELET_IDENTIFIANT = 'RFID-DEMO-0001';

    public function getDependencies(): array
    {
        return [SocleFixtures::class, OffreFixtures::class, AccesFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // --- Permissions piscine.* + octroi à l'administrateur (RG-SOCLE-02/03) ---
        $perms = [];
        foreach (['configurer', 'gerer_casier', 'forcer_casier', 'lire', 'gerer'] as $action) {
            $perm = $this->permissionPour($manager, 'piscine', $action);
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

        // --- Paramètres piscine établissement (délai forçage, caution défaut, prorata défaut) ---
        $parametre = (new ParametrePiscineEtablissement())
            ->setEtablissement($etabA)
            ->setDelaiForcageCasierJours(3)
            ->setMontantCautionCasierDefaut('10.00');
        $manager->persist($parametre);

        // --- Poss : délègue au EspaceAcces L3 déjà créé par AccesFixtures (modeSeuil=blocage) ---
        $espaceAcces = $manager->getRepository(EspaceAcces::class)->findOneBy(['libelle' => AccesFixtures::ESPACE_LIBELLE]);
        if ($espaceAcces instanceof EspaceAcces) {
            $poss = (new Poss())
                ->setEspaceAcces($espaceAcces)
                ->setPerimetre(PerimetrePoss::Etablissement)
                ->setBaseReglementaire('⚠ À VALIDER PAR EXPLOITANT — arrêté ERP type X, commission de sécurité (spec §7)')
                ->setReservationsProtegees(true);
            $manager->persist($poss);
        }

        // --- Bassin + 4 lignes d'eau ---
        $espaceBassin = (new Espace())->setNom(self::BASSIN_LIBELLE)->setEtablissement($etabA)->setType('bassin');
        $manager->persist($espaceBassin);

        $bassin = (new Bassin())
            ->setLibelle(self::BASSIN_LIBELLE)
            ->setEspace($espaceBassin)
            ->setNbLignes(4)
            ->setCapacite(60);
        $manager->persist($bassin);

        for ($numero = 1; $numero <= 4; ++$numero) {
            $manager->persist((new LigneEau())->setNumero($numero)->setBassin($bassin));
        }

        // --- Casier occupé + caution active (bracelet RFID) ---
        $support = (new Support())->setIdentifiant(self::BRACELET_IDENTIFIANT)->setType(TypeSupport::Rfid)->setEtablissement($etabA);
        $manager->persist($support);

        $bracelet = (new BraceletEtanche())->setSupport($support)->setRoles([BraceletEtanche::ROLE_ACCES, BraceletEtanche::ROLE_CASIER]);
        $manager->persist($bracelet);

        $casier = (new Casier())
            ->setNumero(self::CASIER_NUMERO)
            ->setZone(self::CASIER_ZONE)
            ->setEtat(EtatCasier::Occupe)
            ->setBracelet($bracelet)
            ->setEtablissement($etabA);
        $manager->persist($casier);

        $caution = (new CautionCasier())
            ->setCasier($casier)
            ->setMontant('10.00')
            ->setStatut(StatutCaution::Encaissee)
            ->setDateEncaissement(new \DateTimeImmutable());
        $manager->persist($caution);

        // Caution générique miroir (refactor caution générique, `App\Caution\Service\GestionCaution`
        // désormais source de la logique de consignation/restitution/retenue).
        $cautionGenerique = (new Caution())
            ->setEtablissement($etabA)
            ->setTypeCible(\App\Piscine\Service\AttribuerCasierHandler::TYPE_CIBLE)
            ->setReferenceCible((string) $casier->getId())
            ->setMontantCentimes(1000)
            ->setStatut(StatutCautionGenerique::Consignee)
            ->setDateConsignation(new \DateTimeImmutable());
        $manager->persist($cautionGenerique);

        // --- Encadrant MNS à diplôme valide (rattaché à l'administrateur, hypothèse spec §3) ---
        $admin = $manager->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        if ($admin instanceof Utilisateur) {
            $qualification = (new QualificationEncadrant())
                ->setEncadrant($admin)
                ->setType(TypeEncadrement::Mns)
                ->setDateValidite(new \DateTimeImmutable('+1 year'))
                ->setEtablissement($etabA);
            $manager->persist($qualification);
        }

        $manager->flush();
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
