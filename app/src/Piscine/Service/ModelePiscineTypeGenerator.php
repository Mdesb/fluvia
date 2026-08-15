<?php

declare(strict_types=1);

namespace App\Piscine\Service;

use App\Offre\Entity\CarteMultiEntrees;
use App\Offre\Entity\Categorie;
use App\Offre\Entity\Formule;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Saison;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\AxeCategorie;
use App\Offre\Enum\PeriodiciteFormule;
use App\Offre\Enum\StatutProduit;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Instancie le modèle « piscine type » (US-L6-01, CA-1) : crée en un clic les produits **entrée
 * adulte/enfant, carte 10 (bonus 10=12), abonnement Gold, abonnement Classique et cours** via le
 * module Offre (M1, `App\Offre\Entity\{Produit,Formule,CarteMultiEntrees}`, existants — **aucune
 * nouvelle table L6** pour le modèle lui-même, plan §1.7). Chaque produit généré reste **entièrement
 * éditable** ensuite (le générateur ne crée aucun lien vivant vers un « modèle » — gabarit
 * d'instanciation, pas un template figé).
 *
 * ⚠ HYPOTHÈSE — le contenu exact du gabarit (tarifs par défaut, bonus carte, quotas Gold, nombre de
 * cours inclus) n'est pas chiffré dans les sources (spec §4.1 point ouvert n°9) : valeurs de départ
 * paramétrables, centralisées ici plutôt que dispersées (constitution §4).
 */
final class ModelePiscineTypeGenerator
{
    private const CODE_TYPE_ENTREE = 'piscine_entree_unitaire';
    private const CODE_TYPE_ABONNEMENT = 'piscine_abonnement';
    private const CODE_TYPE_CARTE = 'piscine_carte';
    private const CODE_TYPE_COURS = 'piscine_cours';

    private const TARIF_PUBLIC = 'Tarif public piscine';
    private const SAISON = 'Saison piscine (permanente)';
    private const CATEGORIE_COMPTABLE = 'Billetterie piscine (compte 7061)';

    /** @var list<array{code: string, libelle: string, prix: string}> Cours inclus au gabarit (US-L6-01). */
    private const COURS = [
        ['code' => 'PISC-COURS-AQUAGYM', 'libelle' => 'Aquagym', 'prix' => '12.00'],
        ['code' => 'PISC-COURS-AQUABIKE', 'libelle' => 'Aquabike', 'prix' => '14.00'],
        ['code' => 'PISC-COURS-AQUAZUMBA', 'libelle' => 'Aquazumba', 'prix' => '14.00'],
        ['code' => 'PISC-COURS-LECONS', 'libelle' => 'Leçons de natation', 'prix' => '18.00'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<Produit> */
    public function instancier(Etablissement $etablissement): array
    {
        $typeEntree = $this->trouverOuCreerTypeProduit(self::CODE_TYPE_ENTREE, 'Entrée unitaire piscine', [
            TypeProduit::FACETTE_BILLET, TypeProduit::FACETTE_CONSOMMATEUR,
        ]);
        $typeAbonnement = $this->trouverOuCreerTypeProduit(self::CODE_TYPE_ABONNEMENT, 'Abonnement piscine', [
            TypeProduit::FACETTE_FORMULE, TypeProduit::FACETTE_ACCES,
        ]);
        $typeCarte = $this->trouverOuCreerTypeProduit(self::CODE_TYPE_CARTE, 'Carte multi-entrées piscine', [
            TypeProduit::FACETTE_CARNET, TypeProduit::FACETTE_CONSOMMATEUR,
        ]);
        $typeCours = $this->trouverOuCreerTypeProduit(self::CODE_TYPE_COURS, 'Cours piscine', [
            TypeProduit::FACETTE_BILLET, TypeProduit::FACETTE_CONSOMMATEUR,
        ]);

        $tarif = $this->trouverOuCreerTypeTarif();
        $saison = $this->trouverOuCreerSaison();
        $categorie = $this->trouverOuCreerCategorieComptable();

        $produits = [];

        $produits[] = $this->creerSiAbsent('PISC-ENTREE-ADULTE', function () use ($typeEntree, $etablissement, $tarif, $saison, $categorie) {
            $produit = (new Produit())
                ->setType($typeEntree)
                ->setLibelle(['fr' => 'Entrée unitaire adulte'])
                ->setCode('PISC-ENTREE-ADULTE')
                ->setCanaux(['guichet', 'en_ligne'])
                ->setStatut(StatutProduit::Brouillon)
                ->addEtablissement($etablissement)
                ->addCategorie($categorie);
            $this->ajouterGrille($produit, $tarif, $saison, '5.50');

            return $produit;
        });

        $produits[] = $this->creerSiAbsent('PISC-ENTREE-ENFANT', function () use ($typeEntree, $etablissement, $tarif, $saison, $categorie) {
            $produit = (new Produit())
                ->setType($typeEntree)
                ->setLibelle(['fr' => 'Entrée unitaire enfant'])
                ->setCode('PISC-ENTREE-ENFANT')
                ->setCanaux(['guichet', 'en_ligne'])
                ->setStatut(StatutProduit::Brouillon)
                ->addEtablissement($etablissement)
                ->addCategorie($categorie);
            $this->ajouterGrille($produit, $tarif, $saison, '3.50');

            return $produit;
        });

        $produits[] = $this->creerSiAbsent('PISC-CARTE-10', function () use ($typeCarte, $etablissement, $tarif, $saison, $categorie) {
            // Bonus « 10=12 » paramétrable (RG-M1-04/13) : 10 payées, 12 créditées par défaut.
            $carte = (new CarteMultiEntrees())->setNbPaye(10)->setNbCredite(12);
            $produit = (new Produit())
                ->setType($typeCarte)
                ->setLibelle(['fr' => 'Carte 10=12 piscine'])
                ->setCode('PISC-CARTE-10')
                ->setCanaux(['guichet'])
                ->setCarte($carte)
                ->setStatut(StatutProduit::Brouillon)
                ->addEtablissement($etablissement)
                ->addCategorie($categorie);
            $this->ajouterGrille($produit, $tarif, $saison, '45.00');

            return $produit;
        });

        $produits[] = $this->creerSiAbsent('PISC-ABO-GOLD', function () use ($typeAbonnement, $etablissement, $tarif, $saison, $categorie) {
            $formule = (new Formule())
                ->setPeriodicite(PeriodiciteFormule::Mensuel)
                ->setDroitAcces(['mode' => 'illimite'])
                ->setRenouvellement(['auto' => true, 'prix' => 'fixe']);
            $produit = (new Produit())
                ->setType($typeAbonnement)
                ->setLibelle(['fr' => 'Abonnement Gold'])
                ->setCode('PISC-ABO-GOLD')
                ->setCanaux(['guichet', 'en_ligne'])
                ->setFormule($formule)
                ->setStatut(StatutProduit::Brouillon)
                ->addEtablissement($etablissement)
                ->addCategorie($categorie);
            $this->ajouterGrille($produit, $tarif, $saison, '39.90');

            return $produit;
        });

        $produits[] = $this->creerSiAbsent('PISC-ABO-CLASSIQUE', function () use ($typeAbonnement, $etablissement, $tarif, $saison, $categorie) {
            $formule = (new Formule())
                ->setPeriodicite(PeriodiciteFormule::Mensuel)
                ->setDroitAcces(['mode' => 'illimite'])
                ->setRenouvellement(['auto' => true, 'prix' => 'fixe']);
            $produit = (new Produit())
                ->setType($typeAbonnement)
                ->setLibelle(['fr' => 'Abonnement Classique'])
                ->setCode('PISC-ABO-CLASSIQUE')
                ->setCanaux(['guichet', 'en_ligne'])
                ->setFormule($formule)
                ->setStatut(StatutProduit::Brouillon)
                ->addEtablissement($etablissement)
                ->addCategorie($categorie);
            $this->ajouterGrille($produit, $tarif, $saison, '29.90');

            return $produit;
        });

        foreach (self::COURS as $cours) {
            $produits[] = $this->creerSiAbsent($cours['code'], function () use ($cours, $typeCours, $etablissement, $tarif, $saison, $categorie) {
                $produit = (new Produit())
                    ->setType($typeCours)
                    ->setLibelle(['fr' => $cours['libelle']])
                    ->setCode($cours['code'])
                    ->setCanaux(['guichet', 'en_ligne'])
                    ->setStatut(StatutProduit::Brouillon)
                    ->addEtablissement($etablissement)
                    ->addCategorie($categorie);
                $this->ajouterGrille($produit, $tarif, $saison, $cours['prix']);

                return $produit;
            });
        }

        $this->em->flush();

        return array_values(array_filter($produits));
    }

    /** @param list<string> $facettes */
    private function trouverOuCreerTypeProduit(string $code, string $libelle, array $facettes): TypeProduit
    {
        $type = $this->em->getRepository(TypeProduit::class)->findOneBy(['code' => $code]);
        if ($type instanceof TypeProduit) {
            return $type;
        }
        $type = (new TypeProduit())->setCode($code)->setLibelle($libelle)->setFacettes($facettes);
        $this->em->persist($type);

        return $type;
    }

    private function trouverOuCreerTypeTarif(): TypeTarif
    {
        $tarif = $this->em->getRepository(TypeTarif::class)->findOneBy(['nom' => self::TARIF_PUBLIC]);
        if ($tarif instanceof TypeTarif) {
            return $tarif;
        }
        $tarif = (new TypeTarif())->setNom(self::TARIF_PUBLIC)->setVisibiliteCanal([]);
        $this->em->persist($tarif);

        return $tarif;
    }

    private function trouverOuCreerSaison(): Saison
    {
        $saison = $this->em->getRepository(Saison::class)->findOneBy(['nom' => self::SAISON]);
        if ($saison instanceof Saison) {
            return $saison;
        }
        $saison = (new Saison())
            ->setNom(self::SAISON)
            ->setDateDebut(new \DateTimeImmutable('2000-01-01'))
            ->setDateFin(new \DateTimeImmutable('2100-12-31'))
            ->setPriorite(0);
        $this->em->persist($saison);

        return $saison;
    }

    private function trouverOuCreerCategorieComptable(): Categorie
    {
        $categorie = $this->em->getRepository(Categorie::class)->findOneBy(['libelle' => self::CATEGORIE_COMPTABLE]);
        if ($categorie instanceof Categorie) {
            return $categorie;
        }
        $categorie = (new Categorie())->setAxe(AxeCategorie::Comptable)->setLibelle(self::CATEGORIE_COMPTABLE);
        $this->em->persist($categorie);

        return $categorie;
    }

    private function ajouterGrille(Produit $produit, TypeTarif $tarif, Saison $saison, string $prix): void
    {
        $grille = (new GrilleTarifaire())->setProduit($produit)->setTypeTarif($tarif)->setSaison($saison)->setPrix($prix);
        $produit->addGrille($grille);
        $this->em->persist($grille);
    }

    /** @param callable(): Produit $fabrique Idempotence : un produit déjà créé (même code) n'est pas dupliqué. */
    private function creerSiAbsent(string $code, callable $fabrique): ?Produit
    {
        $existant = $this->em->getRepository(Produit::class)->findOneBy(['code' => $code]);
        if ($existant instanceof Produit) {
            return null;
        }

        $produit = $fabrique();
        $this->em->persist($produit);

        return $produit;
    }
}
