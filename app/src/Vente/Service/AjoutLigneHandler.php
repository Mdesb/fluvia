<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Offre\Entity\Produit;
use App\Offre\Entity\Promotion;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\Canal;
use App\Offre\Enum\TypePromotion;
use App\Offre\Service\ResolveurPrix;
use App\OptionProduit\Entity\OptionProduit;
use App\OptionProduit\Entity\ValeurOption;
use App\OptionProduit\Enum\ImpactOptionType;
use App\OptionProduit\Enum\ModeSelectionOption;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use App\Vente\Enum\RemiseType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Résolveur de panier (US-L2-03). Ajoute une ligne au panier : prix issu de la grille M1 via
 * ResolveurPrix (tarif × saison du jour + QF, RG-M1-01), garde « bénéficiaire requis » (CA-5),
 * garde « stock épuisé » (CA-6), application automatique des promotions éligibles (CA-4), quantité
 * minimale 1, recalcul instantané du total. Le forçage de prix exige le droit vente.forcer_prix.
 */
final class AjoutLigneHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurPrix $resolveurPrix,
        private readonly PanierCalculateur $calculateur,
    ) {
    }

    /**
     * @param array<string, mixed> $donnees
     */
    public function ajouter(Vente $vente, array $donnees, bool $autoriseForcage): LigneVente
    {
        if ($vente->estScellee()) {
            throw new ConflictHttpException('Vente validée : le panier est figé (NF525).');
        }

        $produit = $this->resoudre(Produit::class, $donnees['produit'] ?? null, 'produit');
        \assert($produit instanceof Produit);
        $typeTarif = $this->resoudre(TypeTarif::class, $donnees['typeTarif'] ?? null, 'typeTarif');
        \assert($typeTarif instanceof TypeTarif);

        $quantite = (int) ($donnees['quantite'] ?? 1);
        if ($quantite < 1) {
            throw new UnprocessableEntityHttpException('La quantité doit être au minimum de 1 (US-L2-03).');
        }

        // CA-6 — stock géré à 0 : non ajoutable ; produit non géré : jamais bloqué.
        $stock = $produit->getStock();
        if ($stock !== null && $stock->disponibiliteEffective() < $quantite) {
            throw new UnprocessableEntityHttpException('stock épuisé (RG-M2-04).');
        }

        // CA-5 — bénéficiaire requis pour un produit nominatif.
        $beneficiaire = $this->uuidOuNull($donnees['beneficiaire'] ?? null);
        if ($this->estNominatif($produit) && $beneficiaire === null) {
            throw new UnprocessableEntityHttpException('Bénéficiaire requis pour ce produit nominatif (RG-M2-04).');
        }

        $ligne = new LigneVente();
        if (isset($donnees['id']) && \is_string($donnees['id']) && Uuid::isValid($donnees['id'])) {
            $ligne->setId(Uuid::fromString($donnees['id']));
        }
        $ligne->setProduit($produit->getId());
        $ligne->setTypeTarif($typeTarif->getId());
        $ligne->setQuantite($quantite);
        $ligne->setBeneficiaire($beneficiaire);
        if (isset($donnees['note']) && \is_string($donnees['note'])) {
            $ligne->setNote($donnees['note']);
        }

        // Remise de ligne éventuelle.
        if (isset($donnees['remiseLigne'], $donnees['remiseType'])) {
            $type = RemiseType::tryFrom((string) $donnees['remiseType']);
            if ($type !== null) {
                $ligne->setRemiseLigne(number_format((float) $donnees['remiseLigne'], 2, '.', ''));
                $ligne->setRemiseType($type);
            }
        }

        // Prix : issu de la grille M1, sauf forçage explicite avec droit.
        $qf = isset($donnees['qf']) ? (float) $donnees['qf'] : null;
        $forcer = ($donnees['prixForce'] ?? false) === true;
        if ($forcer) {
            if (!$autoriseForcage) {
                throw new UnprocessableEntityHttpException('Forçage de prix non autorisé (droit vente.forcer_prix requis).');
            }
            $ligne->setPrixForce(true);
            $ligne->setPrixUnitaire(number_format((float) ($donnees['prixUnitaire'] ?? 0), 2, '.', ''));
        } else {
            $prix = $this->resolveurPrix->resoudre($produit, $typeTarif, $vente->getDate(), Canal::Guichet, $qf);
            if ($prix === null) {
                throw new UnprocessableEntityHttpException('Produit non commercialisé au guichet pour ce tarif/saison (RG-M1-01/07).');
            }
            $ligne->setPrixUnitaire($prix);
            $ligne->setSaison($this->saisonResolue($produit, $typeTarif, $vente->getDate(), $qf));
        }

        // App\OptionProduit (RG-OPT-03/04/05/07/08) — options sélectionnées, snapshot figé (RG-OPT-09).
        [$snapshotOptions, $impactOptionsCentimes] = $this->resoudreOptions($produit, $donnees['options'] ?? [], $ligne, $vente);
        $ligne->setOptionsSelectionnees($snapshotOptions);
        $ligne->setImpactOptionsUnitaire($this->calculateur->decimal($impactOptionsCentimes));

        // CA-4 — promotions éligibles appliquées automatiquement et visibles sur la ligne.
        $ligne->setPromotionsAppliquees($this->promotionsAuto($produit, $vente->getDate()));

        $vente->addLigne($ligne);
        $this->calculateur->recalculerLigne($ligne);
        $this->calculateur->recalculerVente($vente);

        return $ligne;
    }

    private function estNominatif(Produit $produit): bool
    {
        $type = $produit->getType();
        if ($type instanceof TypeProduit && $type->aFacette(TypeProduit::FACETTE_ACCES)) {
            return true;
        }

        return ($produit->getChampsPerso()['beneficiaireRequis'] ?? false) === true;
    }

    /**
     * @return list<array{id: string, nom: string, type: string, valeur: string|null}>
     */
    private function promotionsAuto(Produit $produit, \DateTimeImmutable $date): array
    {
        /** @var list<Promotion> $promotions */
        $promotions = $this->em->getRepository(Promotion::class)->findAll();
        $appliquees = [];
        foreach ($promotions as $promo) {
            if ($promo->getType() === TypePromotion::Bonus1012 || $promo->getType() === TypePromotion::OffreGroupee) {
                continue; // portées par la carte / logique de groupe, hors calcul de remise ligne.
            }
            if (!$this->promoEligible($promo, $produit, $date)) {
                continue;
            }
            $appliquees[] = [
                'id' => (string) $promo->getId(),
                'nom' => $promo->getNom(),
                'type' => $promo->getType()?->value ?? '',
                'valeur' => $promo->getValeur(),
            ];
        }

        return $appliquees;
    }

    private function promoEligible(Promotion $promo, Produit $produit, \DateTimeImmutable $date): bool
    {
        if ($promo->getDateDebut() !== null && $promo->getDateDebut() > $date) {
            return false;
        }
        if ($promo->getDateFin() !== null && $promo->getDateFin() < $date) {
            return false;
        }
        $canaux = $promo->getCanaux();
        if ($canaux !== null && $canaux !== [] && !\in_array('guichet', $canaux, true)) {
            return false;
        }
        $eligibilite = $promo->getEligibilite() ?? [];
        $produits = $eligibilite['produits'] ?? null;
        if (!\is_array($produits)) {
            return false;
        }

        return \in_array((string) $produit->getId(), array_map('strval', $produits), true);
    }

    private function saisonResolue(Produit $produit, TypeTarif $typeTarif, \DateTimeImmutable $date, ?float $qf): ?Uuid
    {
        foreach ($produit->getGrilles() as $grille) {
            $gt = $grille->getTypeTarif();
            $saison = $grille->getSaison();
            if ($gt === null || $saison === null || !$gt->getId()->equals($typeTarif->getId())) {
                continue;
            }
            if ($saison->isActif() && $saison->contient($date) && $grille->getPrix() !== null) {
                return $saison->getId();
            }
        }

        return null;
    }

    /**
     * Résout et valide les options sélectionnées (App\OptionProduit) pour la ligne en cours de
     * composition : filtre les rattachements actifs/disponibles pour ce produit et cet établissement
     * (RG-OPT-07/08), rejette les références invalides ou hors périmètre (422), applique RG-OPT-05
     * (choix unique) et RG-OPT-03 (obligatoire), puis calcule l'impact tarifaire unitaire (RG-OPT-04).
     * Le snapshot retourné est figé sur la ligne (RG-OPT-09) ; aucune vérification de stock ici
     * (Risque n°1 du plan, hors périmètre de ce lot).
     *
     * @param list<mixed> $refs UUID ou IRI de ValeurOption envoyés par le client
     *
     * @return array{0: list<array{groupeOptionId: string, valeurOptionId: string, libelle: string, impactType: string, impactValeur: string, montantUnitaireApplique: string}>, 1: int}
     */
    private function resoudreOptions(Produit $produit, array $refs, LigneVente $ligne, Vente $vente): array
    {
        /** @var list<OptionProduit> $liaisons */
        $liaisons = $this->em->getRepository(OptionProduit::class)->findBy(['produit' => $produit, 'actif' => true]);

        $etablissement = $vente->getEtablissement();
        $disponibles = [];
        foreach ($liaisons as $liaison) {
            $groupe = $liaison->getGroupeOption();
            if ($groupe === null || !$groupe->isActif()) {
                continue;
            }
            $restrictions = $liaison->getEtablissementsRestriction();
            if (!$restrictions->isEmpty() && ($etablissement === null || !$restrictions->contains($etablissement))) {
                continue; // RG-OPT-07 : hors établissement actif.
            }
            $disponibles[] = $liaison;
        }

        /** @var array<string, array{groupe: \App\OptionProduit\Entity\GroupeOption, valeurs: list<ValeurOption>}> $valeursParGroupe */
        $valeursParGroupe = [];
        foreach ($refs as $ref) {
            $uuid = $this->uuidOuNull($ref);
            $valeur = $uuid !== null ? $this->em->getRepository(ValeurOption::class)->find($uuid) : null;
            if (!$valeur instanceof ValeurOption || !$valeur->isActif()) {
                throw new UnprocessableEntityHttpException('Option indisponible ou inconnue (RG-OPT-08).');
            }
            $groupe = $valeur->getGroupeOption();
            $liaisonTrouvee = null;
            foreach ($disponibles as $liaison) {
                if ($groupe !== null && $liaison->getGroupeOption() === $groupe) {
                    $liaisonTrouvee = $liaison;
                    break;
                }
            }
            if ($groupe === null || $liaisonTrouvee === null) {
                throw new UnprocessableEntityHttpException('Option indisponible ou inconnue pour ce produit/cet établissement (RG-OPT-07).');
            }
            $groupeId = (string) $groupe->getId();
            $valeursParGroupe[$groupeId] ??= ['groupe' => $groupe, 'valeurs' => []];
            $valeursParGroupe[$groupeId]['valeurs'][] = $valeur;
        }

        // RG-OPT-05 — choix unique : une seule valeur retenue par groupe « unique ».
        foreach ($valeursParGroupe as $entree) {
            if ($entree['groupe']->getModeSelection() === ModeSelectionOption::Unique && \count($entree['valeurs']) > 1) {
                throw new UnprocessableEntityHttpException(sprintf('Un seul choix autorisé pour le groupe « %s » (RG-OPT-05).', $entree['groupe']->getLibelle()));
            }
        }

        // RG-OPT-03 — groupe obligatoire : au moins une valeur retenue.
        foreach ($disponibles as $liaison) {
            if (!$liaison->isObligatoire()) {
                continue;
            }
            $groupeId = (string) $liaison->getGroupeOption()?->getId();
            if (($valeursParGroupe[$groupeId]['valeurs'] ?? []) === []) {
                throw new UnprocessableEntityHttpException(sprintf('Option obligatoire manquante : %s (RG-OPT-03).', $liaison->getGroupeOption()?->getLibelle()));
            }
        }

        // RG-OPT-04 — impact unitaire = Σ(montants fixes) + prixBase × Σ(pourcentages), figé (RG-OPT-09).
        $prixBaseCentimes = $this->calculateur->centimes($ligne->getPrixUnitaire());
        $impactTotal = 0;
        $snapshot = [];
        foreach ($valeursParGroupe as $entree) {
            foreach ($entree['valeurs'] as $valeur) {
                $impactUnitaire = $valeur->getImpactType() === ImpactOptionType::Pourcentage
                    ? intdiv($prixBaseCentimes * $this->calculateur->centimes($valeur->getImpactValeur()), 100 * 100)
                    : $this->calculateur->centimes($valeur->getImpactValeur());
                $impactTotal += $impactUnitaire;
                $snapshot[] = [
                    'groupeOptionId' => (string) $valeur->getGroupeOption()?->getId(),
                    'valeurOptionId' => (string) $valeur->getId(),
                    'libelle' => $valeur->getLibelle(),
                    'impactType' => $valeur->getImpactType()->value,
                    'impactValeur' => $valeur->getImpactValeur(),
                    'montantUnitaireApplique' => $this->calculateur->decimal($impactUnitaire),
                ];
            }
        }

        return [$snapshot, $impactTotal];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function resoudre(string $classe, mixed $reference, string $champ): object
    {
        $uuid = $this->uuidOuNull($reference);
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException(sprintf('Référence « %s » obligatoire (UUID ou IRI).', $champ));
        }
        $entite = $this->em->getRepository($classe)->find($uuid);
        if ($entite === null) {
            throw new UnprocessableEntityHttpException(sprintf('%s introuvable.', $champ));
        }

        return $entite;
    }

    private function uuidOuNull(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
