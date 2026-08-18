<?php

declare(strict_types=1);

namespace App\Tests\OptionProduit;

use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Saison;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\StatutProduit;
use App\Organisation\Entity\Etablissement;
use App\OptionProduit\Entity\GroupeOption;
use App\OptionProduit\Entity\OptionProduit;
use App\OptionProduit\Entity\ValeurOption;
use App\OptionProduit\Enum\ImpactOptionType;
use App\OptionProduit\Enum\ModeSelectionOption;
use App\Tests\Vente\VenteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base des tests d'intégration `App\OptionProduit` côté vente (panier, snapshot, contre-passation).
 * Construit le référentiel d'options directement via l'EntityManager (même patron que
 * `VenteFixtures` pour `Promotion`/`Stock`) : ces tests exercent `AjoutLigneHandler`/
 * `PanierCalculateur` (routes M2 déjà mappées) sans dépendre de l'enregistrement des routes CRUD
 * `GroupeOption`/`ValeurOption`/`OptionProduit` dans `api_platform.yaml` (fichier co-édité, hors
 * périmètre de ce lot — cf. rapport final).
 */
abstract class OptionProduitApiTestCase extends VenteApiTestCase
{
    protected function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /**
     * Produit dédié aux tests d'options : prix de grille contrôlé, non nominatif, non géré en stock.
     *
     * @return array{0: Produit, 1: TypeTarif}
     */
    protected function creerProduitBase(string $prix = '20.00'): array
    {
        $em = $this->em();
        $type = $em->getRepository(TypeProduit::class)->findOneBy(['code' => OffreFixtures::TYPE_ENTREE]);
        $tarif = $em->getRepository(TypeTarif::class)->findOneBy(['nom' => OffreFixtures::TARIF_PLEIN]);
        $saison = $em->getRepository(Saison::class)->findOneBy(['nom' => OffreFixtures::SAISON]);
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(TypeProduit::class, $type);
        self::assertInstanceOf(TypeTarif::class, $tarif);
        self::assertInstanceOf(Saison::class, $saison);
        self::assertInstanceOf(Etablissement::class, $etab);

        $code = 'OPT-' . bin2hex(random_bytes(4));
        $produit = (new Produit())
            ->setType($type)
            ->setLibelle(['fr' => 'Produit test options'])
            ->setLibelleRecherche($code)
            ->setCode($code)
            ->setCanaux(['guichet'])
            ->setStatut(StatutProduit::Brouillon);
        $produit->addEtablissement($etab);
        $em->persist($produit);
        $grille = (new GrilleTarifaire())->setProduit($produit)->setTypeTarif($tarif)->setSaison($saison)->setPrix($prix);
        $produit->addGrille($grille);
        $em->persist($grille);
        $em->flush();

        return [$produit, $tarif];
    }

    protected function creerGroupeOption(string $libelle, ModeSelectionOption $mode = ModeSelectionOption::Multiple, bool $actif = true): GroupeOption
    {
        $groupe = (new GroupeOption())->setLibelle($libelle)->setModeSelection($mode)->setActif($actif);
        $this->em()->persist($groupe);
        $this->em()->flush();

        return $groupe;
    }

    protected function creerValeurOption(
        GroupeOption $groupe,
        string $libelle,
        ImpactOptionType $impactType,
        string $impactValeur,
        bool $actif = true,
    ): ValeurOption {
        $valeur = (new ValeurOption())
            ->setGroupeOption($groupe)
            ->setLibelle($libelle)
            ->setImpactType($impactType)
            ->setImpactValeur($impactValeur)
            ->setActif($actif);
        $this->em()->persist($valeur);
        $this->em()->flush();

        return $valeur;
    }

    /**
     * @param list<Etablissement> $etablissementsRestriction
     */
    protected function creerOptionProduit(
        Produit $produit,
        GroupeOption $groupe,
        bool $obligatoire = false,
        array $etablissementsRestriction = [],
        bool $actif = true,
    ): OptionProduit {
        $liaison = (new OptionProduit())
            ->setProduit($produit)
            ->setGroupeOption($groupe)
            ->setObligatoire($obligatoire)
            ->setActif($actif);
        foreach ($etablissementsRestriction as $etab) {
            $liaison->addEtablissementRestriction($etab);
        }
        $this->em()->persist($liaison);
        $this->em()->flush();

        return $liaison;
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    protected function entetePatch(array $entete): array
    {
        $entete['headers'] = ($entete['headers'] ?? []) + ['Content-Type' => 'application/merge-patch+json'];

        return $entete;
    }
}
