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
use App\Platform\DataFixtures\FixturesIdempotentes;
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
    use FixturesIdempotentes;

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
        $permOffreTout = $this->permissionNommee($manager, 'offre', '*');
        foreach (['creer', 'lire', 'modifier', 'publier', 'archiver', 'gerer', 'modifier_compta'] as $action) {
            $this->permissionNommee($manager, 'offre', $action);
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permOffreTout);
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);

        // --- Types de produits (facettes + matrice de conversion RG-M1-11) ---
        // `TypeProduit.code` porte `uniq_type_produit_code` : il crie au rechargement. Les trois
        // référentiels qui suivent ne crient pas — `TypeTarif`, `Saison` et `Categorie` n'ont d'unicité
        // que sur leur identifiant technique, donc un second chargement les **dupliquerait en
        // silence**, et le catalogue de démonstration afficherait deux fois chaque tarif (D52).
        $typeEntree = $this->parCode($manager, TypeProduit::class, self::TYPE_ENTREE);
        $typeEntree->setLibelle('Entrée unitaire')
            ->setFacettes([TypeProduit::FACETTE_BILLET, TypeProduit::FACETTE_CONSOMMATEUR]);

        $typeAbo = $this->parCode($manager, TypeProduit::class, self::TYPE_ABONNEMENT);
        $typeAbo->setLibelle('Abonnement')
            ->setFacettes([TypeProduit::FACETTE_FORMULE, TypeProduit::FACETTE_ACCES]);

        $typeCarte = $this->parCode($manager, TypeProduit::class, self::TYPE_CARTE);
        $typeCarte->setLibelle('Carte multi-entrées')
            ->setFacettes([TypeProduit::FACETTE_CARNET, TypeProduit::FACETTE_CONSOMMATEUR]);

        // entree_unitaire ↔ carte : conversion assistée compatible (CA-13). `addTypeCompatible` est
        // gardé par `contains`, donc réattacher sur un type réutilisé est sans effet.
        $typeEntree->addTypeCompatible($typeCarte);
        $typeCarte->addTypeCompatible($typeEntree);

        // --- Référentiels tarifaires ---
        $tarifPlein = $this->parNom($manager, TypeTarif::class, self::TARIF_PLEIN);
        $tarifPlein->setVisibiliteCanal([]);
        $tarifGuichet = $this->parNom($manager, TypeTarif::class, self::TARIF_GUICHET);
        $tarifGuichet->setVisibiliteCanal(['guichet']);

        $saison = $this->parNom($manager, Saison::class, self::SAISON);
        $saison->setDateDebut(new \DateTimeImmutable('2026-01-01'))
            ->setDateFin(new \DateTimeImmutable('2026-12-31'))
            ->setPriorite(0);
        // D51 — une saison appartient a un etablissement, elle n a pas de socle : sans rattachement,
        // elle n est visible de personne et les grilles tarifaires ne resolvent plus aucun prix. Ici on
        // SAIT a qui elle appartient, c est un jeu de donnees — la migration, elle, laisse orphelin
        // plutot que d inventer un proprietaire sur des donnees reelles.
        if ($etabA instanceof Etablissement) {
            $saison->setEtablissement($etabA);
        }

        // --- Catégories (axe comptable obligatoire + axe marketing) ---
        // Une catégorie s'identifie par son libellé ET son axe : le même libellé peut exister sur deux
        // axes sans être un doublon.
        $catCompta = $this->categorie($manager, AxeCategorie::Comptable, self::CAT_COMPTABLE);
        $catMarketing = $this->categorie($manager, AxeCategorie::Marketing, 'Aquatique');

        // ACT-5 : LES CATEGORIES PAR DEFAUT D'UN TYPE, POSEES ICI PARCE QUE `TypeProduit` EST EN
        // LECTURE SEULE PAR L'API -- c'est un referentiel structurel, pas de la donnee utilisateur.
        //
        // L'axe comptable est OBLIGATOIRE POUR PUBLIER (RG-M1-05). Sans defaut, chaque produit cree
        // reste bloque en brouillon, et l'exploitant qui ne connait pas la regle cherche pourquoi son
        // produit ne se vend pas.
        //
        // Les categories sont designees par LIBELLE et non par identifiant : `Categorie` est un
        // referentiel << socle + ajout local >> (D51), et un identifiant fige ici designerait la
        // categorie d'un seul etablissement -- rendant le type inutilisable ailleurs, sans erreur, en
        // n'appliquant simplement rien.
        //
        // Pose APRES la creation des categories : un defaut qui nomme une categorie inexistante ne
        // leve pas, il ne fait rien.
        $typeEntree->setDefauts(['categories' => [
            'comptable' => self::CAT_COMPTABLE,
            'marketing' => 'Aquatique',
        ]]);
        $typeAbo->setDefauts(['categories' => ['comptable' => self::CAT_COMPTABLE]]);
        $typeCarte->setDefauts(['categories' => ['comptable' => self::CAT_COMPTABLE]]);

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

    /**
     * Une catégorie s'identifie par le couple (axe, libellé) — le même libellé sur deux axes n'est pas
     * un doublon. Aucune unicité en base, donc un rechargement la duplique sans lever.
     */
    private function categorie(ObjectManager $manager, AxeCategorie $axe, string $libelle): Categorie
    {
        $existante = $manager->getRepository(Categorie::class)
            ->findOneBy(['axe' => $axe, 'libelle' => $libelle]);

        if ($existante instanceof Categorie) {
            return $existante;
        }

        $categorie = (new Categorie())->setAxe($axe)->setLibelle($libelle);
        $manager->persist($categorie);

        return $categorie;
    }
}
