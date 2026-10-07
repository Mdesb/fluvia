<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\Canal;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
        // **Un seul calcul, deux appelants.** La saison retenue et les promotions automatiques
        // vivaient ici, en methodes privees — donc inatteignables depuis l estimation de prix que la
        // caisse doit pouvoir demander AVANT qu une vente existe. Les y laisser aurait force
        // l estimation a les reecrire, et deux implementations des memes regles ne divergent pas au
        // moment ou on les ecrit : elles divergent au premier correctif applique a une seule des deux.
        private readonly PriceQuoter $tarif,
        private readonly CounterSellability $sellability,
    ) {
    }

    /**
     * Caisse en ligne : un produit que cette caisse ne peut pas vendre (non publié, hors canal
     * guichet, hors du site de la vente) est refusé en 422, avant toute écriture.
     *
     * @param array<string, mixed> $donnees
     */
    public function ajouter(Vente $vente, array $donnees, bool $autoriseForcage): LigneVente
    {
        return $this->composerLigne($vente, $donnees, $autoriseForcage, false)[0];
    }

    /**
     * Synchro hors ligne : la vente a DÉJÀ eu lieu et l'argent est dans le tiroir. Un produit devenu
     * invendable (brouillon, archivé, retiré du guichet depuis que le catalogue local a été lu) ne la
     * fait donc pas refuser — un refus l'enverrait en quarantaine, que rien ne persiste : la recette
     * disparaîtrait des comptes alors qu'elle est dans la caisse. La ligne est composée, et le motif
     * est rendu pour que l'appelant trace l'écart.
     *
     * ⚠ SAUF LE SITE. Un produit d'un autre site n'a jamais pu être dans le catalogue local de cette
     * caisse (l'API ne lui sert que son site et le socle) : l'accepter ferait seulement écrire, sur un
     * ticket de ce site, le libellé et le prix d'un autre catalogue. Celui-là reste refusé (404,
     * comme un produit inconnu) : la vente part en quarantaine.
     * Toutes les autres gardes (prix, stock, bénéficiaire, options) restent bloquantes, comme avant.
     *
     * @param array<string, mixed> $donnees
     *
     * @return array{0: LigneVente, 1: string|null} la ligne, et le motif d'invendabilité éventuel
     */
    public function replayOfflineLine(Vente $vente, array $donnees): array
    {
        return $this->composerLigne($vente, $donnees, false, true);
    }

    /**
     * @param array<string, mixed> $donnees
     *
     * @return array{0: LigneVente, 1: string|null}
     */
    private function composerLigne(Vente $vente, array $donnees, bool $autoriseForcage, bool $venteDejaEncaissee): array
    {
        if ($vente->estScellee()) {
            throw new ConflictHttpException('Vente validée : le panier est figé (NF525).');
        }

        $produit = $this->produitDuSite($donnees['produit'] ?? null, $vente);

        // Publié, au guichet. Le site est déjà réglé par `produitDuSite()` (404, synchro comprise) ;
        // ce qui reste est l'état commercial, que la synchro hors ligne trace au lieu de refuser.
        $invendable = $this->sellability->offerRefusal($produit);
        if ($invendable !== null && !$venteDejaEncaissee) {
            throw new UnprocessableEntityHttpException($invendable);
        }
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
            $ligne->setSaison($this->tarif->saison($produit, $typeTarif, $vente->getDate(), $qf));
        }

        // App\OptionProduit (RG-OPT-03/04/05/07/08) — options sélectionnées, snapshot figé (RG-OPT-09).
        [$snapshotOptions, $impactOptionsCentimes] = $this->resoudreOptions($produit, $donnees['options'] ?? [], $ligne, $vente);
        $ligne->setOptionsSelectionnees($snapshotOptions);
        $ligne->setImpactOptionsUnitaire($this->calculateur->decimal($impactOptionsCentimes));

        // CA-4 — promotions éligibles appliquées automatiquement et visibles sur la ligne.
        $ligne->setPromotionsAppliquees($this->tarif->promotionsAuto($produit, $vente->getDate()));

        $vente->addLigne($ligne);
        $this->calculateur->recalculerLigne($ligne);
        $this->calculateur->recalculerVente($vente);

        return [$ligne, $invendable];
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
                // Un seul calcul, deux appelants : la meme formule sert a l estimation que la caisse
                // demande AVANT l ajout au panier. Une seconde implementation ne divergerait pas au
                // moment ou on l ecrit — elle divergerait au premier correctif applique a une seule.
                $impactUnitaire = $this->tarif->impactOption($valeur, $prixBaseCentimes);
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
     * Le produit, s'il existe ET s'il est au catalogue du site de la vente (D92 : aucun site = socle).
     *
     * ⚠ UN SEUL `throw` POUR LES DEUX CAS, ET C'EST LA GARANTIE (D3). Un produit d'un autre site
     * n'existe pas ici : la réponse doit être indiscernable de celle d'un UUID inconnu — même statut,
     * même message, même ligne levée. Deux `throw` au texte identique divergeraient au premier
     * reformulé ; et un 422 « pas vendu sur ce site » confirmait l'existence du produit ailleurs.
     */
    private function produitDuSite(mixed $reference, Vente $vente): Produit
    {
        $uuid = $this->uuidOuNull($reference);
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException('Référence « produit » obligatoire (UUID ou IRI).');
        }
        $produit = $this->em->getRepository(Produit::class)->find($uuid);
        if (!$produit instanceof Produit || !$this->sellability->isSoldAtSite($produit, $vente->getEtablissement())) {
            throw new NotFoundHttpException('Produit introuvable.');
        }

        return $produit;
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
