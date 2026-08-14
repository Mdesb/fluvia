<?php

declare(strict_types=1);

namespace App\Offre\DataFixtures;

use App\DataFixtures\SocleFixtures;
use App\Offre\Entity\CarteMultiEntrees;
use App\Offre\Entity\Categorie;
use App\Offre\Entity\Formule;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Saison;
use App\Offre\Entity\ServiceInclus;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\AxeCategorie;
use App\Offre\Enum\PeriodiciteFormule;
use App\Offre\Enum\StatutProduit;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Uid\Uuid;

/**
 * Jeu de données M1 (L1) : permissions du module « offre » accordées à l'administrateur, types de
 * produits (avec matrice de conversion), référentiels tarifaires, catégorie comptable, et quelques
 * produits (abonnement Gold, carte 10=12, entrée unitaire) sur l'établissement A du socle.
 */
final class OffreFixtures extends Fixture implements DependentFixtureInterface
{
    public const TYPE_ENTREE = 'entree_unitaire';
    public const TYPE_ABONNEMENT = 'abonnement';
    public const TYPE_CARTE = 'carte';
    public const TARIF_PLEIN = 'Plein tarif';
    public const TARIF_GUICHET = 'Tarif guichet uniquement';
    public const SAISON = 'Saison 2026';
    public const CAT_COMPTABLE = 'Billetterie (compte 7061)';
    public const PRODUIT_ENTREE = 'Entrée unitaire piscine';
    public const PRODUIT_GOLD = 'Abonnement Gold';
    public const PRODUIT_CARTE = 'Carte 10=12 piscine';

    public function getDependencies(): array
    {
        return [SocleFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // --- Permissions du module offre + octroi à l'administrateur (RG-SOCLE-02/03) ---
        $permOffreTout = (new Permission())->setModule('offre')->setAction('*');
        $manager->persist($permOffreTout);
        foreach (['creer', 'lire', 'modifier', 'publier', 'archiver', 'gerer', 'modifier_compta'] as $action) {
            $manager->persist((new Permission())->setModule('offre')->setAction($action));
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permOffreTout);
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);

        // --- Types de produits (facettes + matrice de conversion RG-M1-11) ---
        $typeEntree = (new TypeProduit())
            ->setCode(self::TYPE_ENTREE)
            ->setLibelle('Entrée unitaire')
            ->setFacettes([TypeProduit::FACETTE_BILLET, TypeProduit::FACETTE_CONSOMMATEUR]);
        $typeAbo = (new TypeProduit())
            ->setCode(self::TYPE_ABONNEMENT)
            ->setLibelle('Abonnement')
            ->setFacettes([TypeProduit::FACETTE_FORMULE, TypeProduit::FACETTE_ACCES]);
        $typeCarte = (new TypeProduit())
            ->setCode(self::TYPE_CARTE)
            ->setLibelle('Carte multi-entrées')
            ->setFacettes([TypeProduit::FACETTE_CARNET, TypeProduit::FACETTE_CONSOMMATEUR]);
        // entree_unitaire ↔ carte : conversion assistée compatible (CA-13).
        $typeEntree->addTypeCompatible($typeCarte);
        $typeCarte->addTypeCompatible($typeEntree);
        $manager->persist($typeEntree);
        $manager->persist($typeAbo);
        $manager->persist($typeCarte);

        // --- Référentiels tarifaires ---
        $tarifPlein = (new TypeTarif())->setNom(self::TARIF_PLEIN)->setVisibiliteCanal([]);
        $tarifGuichet = (new TypeTarif())->setNom(self::TARIF_GUICHET)->setVisibiliteCanal(['guichet']);
        $manager->persist($tarifPlein);
        $manager->persist($tarifGuichet);

        $saison = (new Saison())
            ->setNom(self::SAISON)
            ->setDateDebut(new \DateTimeImmutable('2026-01-01'))
            ->setDateFin(new \DateTimeImmutable('2026-12-31'))
            ->setPriorite(0);
        $manager->persist($saison);

        // --- Catégories (axe comptable obligatoire + axe marketing) ---
        $catCompta = (new Categorie())->setAxe(AxeCategorie::Comptable)->setLibelle(self::CAT_COMPTABLE);
        $catMarketing = (new Categorie())->setAxe(AxeCategorie::Marketing)->setLibelle('Aquatique');
        $manager->persist($catCompta);
        $manager->persist($catMarketing);

        // --- Produits ---
        // 1) Entrée unitaire (publiable : libellé + site + canal + prix + cat. comptable).
        $entree = (new Produit())
            ->setType($typeEntree)
            ->setLibelle(['fr' => self::PRODUIT_ENTREE])
            ->setLibelleRecherche(self::PRODUIT_ENTREE)
            ->setCode('PRD-ENTREE01')
            ->setCanaux(['guichet', 'en_ligne'])
            ->setStatut(StatutProduit::Brouillon);
        if ($etabA instanceof Etablissement) {
            $entree->addEtablissement($etabA);
        }
        $entree->addCategorie($catCompta)->addCategorie($catMarketing);
        $manager->persist($entree);
        $this->ajouterGrille($manager, $entree, $tarifPlein, $saison, '5.50');
        $this->ajouterGrille($manager, $entree, $tarifGuichet, $saison, '4.00');

        // 2) Abonnement Gold (formule + service inclus à quota).
        $formule = (new Formule())
            ->setPeriodicite(PeriodiciteFormule::Mensuel)
            ->setDroitAcces(['mode' => 'illimite'])
            ->setRenouvellement(['auto' => true, 'prix' => 'fixe']);
        $service = (new ServiceInclus())
            ->setActiviteRef(Uuid::v4())
            ->setQuota(2);
        $formule->addServiceInclus($service);
        $gold = (new Produit())
            ->setType($typeAbo)
            ->setLibelle(['fr' => self::PRODUIT_GOLD])
            ->setLibelleRecherche(self::PRODUIT_GOLD)
            ->setCode('PRD-GOLD01')
            ->setCanaux(['guichet', 'en_ligne'])
            ->setFormule($formule)
            ->setStatut(StatutProduit::Brouillon);
        if ($etabA instanceof Etablissement) {
            $gold->addEtablissement($etabA);
        }
        $gold->addCategorie($catCompta);
        $manager->persist($gold);
        $this->ajouterGrille($manager, $gold, $tarifPlein, $saison, '39.90');

        // 3) Carte 10=12 (carnet : 10 payées, 12 créditées).
        $carte = (new CarteMultiEntrees())
            ->setNbPaye(10)
            ->setNbCredite(12)
            ->setDateButoir(new \DateTimeImmutable('2026-12-31'));
        $carteProduit = (new Produit())
            ->setType($typeCarte)
            ->setLibelle(['fr' => self::PRODUIT_CARTE])
            ->setLibelleRecherche(self::PRODUIT_CARTE)
            ->setCode('PRD-CARTE01')
            ->setCanaux(['guichet'])
            ->setCarte($carte)
            ->setStatut(StatutProduit::Brouillon);
        if ($etabA instanceof Etablissement) {
            $carteProduit->addEtablissement($etabA);
        }
        $carteProduit->addCategorie($catCompta);
        $manager->persist($carteProduit);
        $this->ajouterGrille($manager, $carteProduit, $tarifPlein, $saison, '45.00');

        $manager->flush();
    }

    private function ajouterGrille(
        ObjectManager $manager,
        Produit $produit,
        TypeTarif $typeTarif,
        Saison $saison,
        ?string $prix,
    ): void {
        $grille = (new GrilleTarifaire())
            ->setProduit($produit)
            ->setTypeTarif($typeTarif)
            ->setSaison($saison)
            ->setPrix($prix);
        $produit->addGrille($grille);
        $manager->persist($grille);
    }
}
