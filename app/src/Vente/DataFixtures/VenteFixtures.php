<?php

declare(strict_types=1);

namespace App\Vente\DataFixtures;

use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Enum\EtatCaisse;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Promotion;
use App\Offre\Entity\Saison;
use App\Offre\Entity\Stock;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\StatutProduit;
use App\Offre\Enum\TypePromotion;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\DataFixtures\SocleFixtures;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données M2 (L2) : permissions vente.* / caisse.* accordées à l'administrateur, un point de
 * vente + une caisse sur l'établissement A (fixtures socle), les moyens de paiement standard
 * autorisés et une promotion guichet éligible à l'entrée unitaire (pour illustrer l'application
 * automatique des promotions, CA-4).
 */
final class VenteFixtures extends Fixture implements DependentFixtureInterface
{
    public const PDV_LIBELLE = 'Guichet principal Piscine A';
    public const CAISSE_LIBELLE = 'Caisse 1';
    public const PROMO_ENTREE = 'Promo guichet -10% entrée';
    public const SEUIL_IMPRESSION = '20.00';
    public const PRODUIT_STOCK_ZERO = 'Cadenas vestiaire (rupture)';
    public const PRODUIT_STOCK_UN = 'Place limitée (stock 1)';

    /** @var list<string> Moyens standard autorisés par défaut sur le point de vente. */
    public const MOYENS = ['especes', 'cb', 'cheque', 'virement', 'cheque_vacances', 'cheque_culture', 'cheque_loisirs', 'pmv', 'avoir', 'differe'];

    public function getDependencies(): array
    {
        return [SocleFixtures::class, OffreFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // --- Permissions vente.* / caisse.* + octroi à l'administrateur (RG-SOCLE-02/03) ---
        $permVente = (new Permission())->setModule('vente')->setAction('*');
        $permCaisse = (new Permission())->setModule('caisse')->setAction('*');
        $manager->persist($permVente);
        $manager->persist($permCaisse);
        foreach (['lire', 'creer', 'encaisser', 'annuler', 'rembourser', 'forcer_prix'] as $action) {
            $manager->persist((new Permission())->setModule('vente')->setAction($action));
        }
        foreach (['lire', 'ouvrir', 'cloturer', 'mouvement', 'gerer'] as $action) {
            $manager->persist((new Permission())->setModule('caisse')->setAction($action));
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permVente)->addPermission($permCaisse);
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);

        // --- Point de vente + caisse sur l'établissement A ---
        $pdv = (new PointDeVente())
            ->setLibelle(self::PDV_LIBELLE)
            ->setSeuilImpression(self::SEUIL_IMPRESSION)
            ->setSeuilAlerteRetrait('200.00')
            ->setMoyensAutorises(self::MOYENS)
            ->setTpe([['marque' => 'ingenico', 'ref' => 'TPE-A1']]);
        if ($etabA instanceof Etablissement) {
            $pdv->setEtablissement($etabA);
        }
        $manager->persist($pdv);

        $caisse = (new Caisse())
            ->setLibelle(self::CAISSE_LIBELLE)
            ->setPointDeVente($pdv)
            ->setEtat(EtatCaisse::Securisee);
        $manager->persist($caisse);

        // --- Promotion guichet éligible à l'entrée unitaire (CA-4) ---
        $entree = $manager->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);
        if ($entree instanceof Produit) {
            $promo = (new Promotion())
                ->setNom(self::PROMO_ENTREE)
                ->setType(TypePromotion::Pourcentage)
                ->setValeur('10.00')
                ->setCumul(Promotion::CUMUL_CUMULABLE)
                ->setCanaux(['guichet'])
                ->setEligibilite(['produits' => [(string) $entree->getId()]]);
            $manager->persist($promo);
        }

        // --- Produit géré en stock à 0 (rupture) pour illustrer le blocage stock (CA-6) ---
        $typeEntree = $manager->getRepository(TypeProduit::class)->findOneBy(['code' => OffreFixtures::TYPE_ENTREE]);
        $tarifPlein = $manager->getRepository(TypeTarif::class)->findOneBy(['nom' => OffreFixtures::TARIF_PLEIN]);
        $saison = $manager->getRepository(Saison::class)->findOneBy(['nom' => OffreFixtures::SAISON]);
        if ($typeEntree instanceof TypeProduit && $tarifPlein instanceof TypeTarif && $saison instanceof Saison) {
            $stock = (new Stock())->setType(Stock::TYPE_DEDIE)->setDisponibilite(0);
            $rupture = (new Produit())
                ->setType($typeEntree)
                ->setLibelle(['fr' => self::PRODUIT_STOCK_ZERO])
                ->setLibelleRecherche(self::PRODUIT_STOCK_ZERO)
                ->setCode('PRD-CADENAS01')
                ->setCanaux(['guichet'])
                ->setStock($stock)
                ->setStatut(StatutProduit::Brouillon);
            if ($etabA instanceof Etablissement) {
                $rupture->addEtablissement($etabA);
            }
            $manager->persist($rupture);
            $grille = (new GrilleTarifaire())->setProduit($rupture)->setTypeTarif($tarifPlein)->setSaison($saison)->setPrix('3.00');
            $rupture->addGrille($grille);
            $manager->persist($grille);

            // Produit géré en stock à 1 : sert au test de décrément atomique (anti-survente, §6).
            $stockUn = (new Stock())->setType(Stock::TYPE_DEDIE)->setDisponibilite(1);
            $limite = (new Produit())
                ->setType($typeEntree)
                ->setLibelle(['fr' => self::PRODUIT_STOCK_UN])
                ->setLibelleRecherche(self::PRODUIT_STOCK_UN)
                ->setCode('PRD-PLACE01')
                ->setCanaux(['guichet'])
                ->setStock($stockUn)
                ->setStatut(StatutProduit::Brouillon);
            if ($etabA instanceof Etablissement) {
                $limite->addEtablissement($etabA);
            }
            $manager->persist($limite);
            $grilleUn = (new GrilleTarifaire())->setProduit($limite)->setTypeTarif($tarifPlein)->setSaison($saison)->setPrix('3.00');
            $limite->addGrille($grilleUn);
            $manager->persist($grilleUn);
        }

        $manager->flush();
    }
}
