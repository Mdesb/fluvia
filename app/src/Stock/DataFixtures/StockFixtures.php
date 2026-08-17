<?php

declare(strict_types=1);

namespace App\Stock\DataFixtures;

use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Saison;
use App\Offre\Entity\Stock;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\StatutProduit;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Stock\Entity\ParametrageStock;
use App\Stock\Enum\MethodeValorisation;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données du module `App\Stock` : permissions `stock.*` accordées à l'administrateur
 * (RG-SOCLE-02/03), paramétrage FIFO par défaut sur les deux établissements socle (US-STOCK-07), un
 * `TypeProduit` à facette `stock` (aucun n'existe dans `OffreFixtures`, dédié aux verticales
 * billetterie) et un `Produit` boutique avec `Stock` dédié à disponibilité 0 — support des tests
 * d'intégration `App\Stock` ↔ M1/M2 (rattachement article, décrément vente, RG-STOCK-01/17).
 */
final class StockFixtures extends Fixture implements DependentFixtureInterface
{
    /** @var list<string> */
    public const ACTIONS = [
        'gerer_article', 'gerer_fournisseur', 'gerer_achat', 'receptionner', 'ajuster',
        'inventorier', 'transferer', 'lire', 'gerer', 'valider_ecart', 'parametrer', 'lire_valorisation',
    ];

    public const TYPE_BOUTIQUE = 'boutique_stock';
    public const PRODUIT_BOUTIQUE = 'Mug boutique piscine A';

    public function getDependencies(): array
    {
        return [SocleFixtures::class, OffreFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $perms = [];
        foreach (self::ACTIONS as $action) {
            $perm = (new Permission())->setModule('stock')->setAction($action);
            $manager->persist($perm);
            $perms[$action] = $perm;
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            foreach ($perms as $perm) {
                $roleAdmin->addPermission($perm);
            }
        }

        foreach ([SocleFixtures::ETAB_A_NOM, SocleFixtures::ETAB_B_NOM] as $nomEtab) {
            $etab = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtab]);
            if (!$etab instanceof Etablissement) {
                continue;
            }
            $parametrage = new ParametrageStock();
            $parametrage->setEtablissement($etab)->setMethodeValorisationDefaut(MethodeValorisation::Fifo);
            $manager->persist($parametrage);
        }

        // --- Type de produit « Boutique » à facette stock (aucun dans OffreFixtures) + Produit démo ---
        $typeBoutique = (new TypeProduit())
            ->setCode(self::TYPE_BOUTIQUE)
            ->setLibelle('Boutique (marchandise)')
            ->setFacettes([TypeProduit::FACETTE_STOCK, TypeProduit::FACETTE_CONSOMMATEUR]);
        $manager->persist($typeBoutique);

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $tarifPlein = $manager->getRepository(TypeTarif::class)->findOneBy(['nom' => OffreFixtures::TARIF_PLEIN]);
        $saison = $manager->getRepository(Saison::class)->findOneBy(['nom' => OffreFixtures::SAISON]);

        if ($etabA instanceof Etablissement && $tarifPlein instanceof TypeTarif && $saison instanceof Saison) {
            $stock = (new Stock())->setType(Stock::TYPE_DEDIE)->setDisponibilite(0);
            $produit = (new Produit())
                ->setType($typeBoutique)
                ->setLibelle(['fr' => self::PRODUIT_BOUTIQUE])
                ->setLibelleRecherche(self::PRODUIT_BOUTIQUE)
                ->setCode('PRD-MUGBOUTIQUE01')
                ->setCanaux(['guichet'])
                ->setStock($stock)
                ->setStatut(StatutProduit::Publie);
            $produit->addEtablissement($etabA);
            $manager->persist($produit);

            $grille = (new GrilleTarifaire())->setProduit($produit)->setTypeTarif($tarifPlein)->setSaison($saison)->setPrix('8.00');
            $produit->addGrille($grille);
            $manager->persist($grille);
        }

        $manager->flush();
    }
}
