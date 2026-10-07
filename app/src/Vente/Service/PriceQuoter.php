<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Offre\Entity\Produit;
use App\Offre\Entity\Promotion;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\Canal;
use App\Offre\Enum\TypePromotion;
use App\Offre\Service\ResolveurPrix;
use App\Vente\Dto\PriceQuote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use App\OptionProduit\Entity\ValeurOption;
use App\OptionProduit\Enum\ImpactOptionType;
use App\OptionProduit\Entity\OptionProduit;
use App\Organisation\Entity\Etablissement;

/**
 * Le prix d'une ligne : **un seul calcul, deux appelants**.
 *
 * **Le défaut que ce service existe pour empêcher.** Maxime a signalé un ticket qui n'additionnait
 * pas — « 1 × Test 10,00 € », total 15,00 €. La vente en base était juste, c'est l'écran qui mentait :
 * la caisse retenait la **première grille tarifaire vendable** du produit, quand le serveur applique le
 * **tarif réellement dû** (saison, quotient familial). Les deux peuvent différer sans que personne
 * soit en faute — tant que la vente n'existe pas, l'écran ne peut qu'estimer.
 *
 * **Et le remède ne doit pas avoir la forme de la maladie.** Écrire ici une seconde implémentation des
 * règles de tarif reproduirait exactement ce défaut, avec deux couches serveur au lieu d'une couche
 * serveur et une couche écran — et cette fois **personne ne verrait la divergence**, puisque aucun
 * écran ne mettrait les deux nombres face à face. Elle se manifesterait comme un client affirmant
 * avoir vu un autre prix, à qui l'on répondrait qu'il se trompe.
 *
 * `AjoutLigneHandler` appelle donc ce service pour composer une ligne, et l'estimation appelle le même.
 * Un calcul parallèle ne diverge pas au moment où on l'écrit : **il diverge au premier correctif
 * appliqué à un seul des deux**, et personne ne se souvient alors qu'il y en avait deux.
 *
 * **Ce que le prix ne prend PAS en compte, et pourquoi c'est écrit ici** : le bénéficiaire. Il
 * n'entre dans aucune règle de tarif du dépôt — c'est le **quotient familial** qui compte, et il est
 * fourni par l'appelant, exactement comme à la création d'une ligne. Accepter un paramètre
 * `beneficiaire` pour l'ignorer aurait été pire que de ne pas l'accepter : l'appelant aurait cru le
 * prix contextualisé.
 */
final class PriceQuoter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurPrix $resolveurPrix,
        private readonly PanierCalculateur $calculateur,
    ) {
    }

    /**
     * Le prix applicable, et la raison. Ne crée rien, ne réserve rien, ne consomme aucun stock.
     */
    /**
     * @param list<string> $optionsRetenues identifiants de `ValeurOption` selectionnes
     */
    public function quote(
        Produit $produit,
        TypeTarif $typeTarif,
        \DateTimeImmutable $date,
        Canal $canal = Canal::Guichet,
        ?float $qf = null,
        ?Etablissement $etablissement = null,
        array $optionsRetenues = [],
        int $quantite = 1,
    ): PriceQuote {
        $prix = $this->resolveurPrix->resoudre($produit, $typeTarif, $date, $canal, $qf);
        $saison = $prix === null ? null : $this->saison($produit, $typeTarif, $date, $qf);

        $baseCentimes = $prix !== null ? $this->calculateur->centimes($prix) : 0;
        $groupes = $this->optionsDuProduit($produit, $etablissement, $baseCentimes, $optionsRetenues);

        // Calculées une fois : elles sont rendues à l'écran ET déduites du montant annoncé.
        $promotions = $prix === null ? [] : $this->promotionsAuto($produit, $date);

        $totalUnitaire = null;
        $totalLigne = null;
        $montantLigne = null;
        if ($prix !== null) {
            $cumul = $baseCentimes;
            foreach ($groupes as $groupe) {
                foreach ($groupe['valeurs'] as $valeur) {
                    // Une option retenue mais indisponible n ajoute rien : la caisse la refuserait, et
                    // annoncer un total que la vente ne produira pas est le defaut qu on ferme ici.
                    if ($valeur['retenue'] === true && $valeur['disponible'] === true) {
                        $cumul += $this->calculateur->centimes($valeur['montantParUnite']);
                    }
                }
            }
            $totalUnitaire = $this->calculateur->decimal($cumul);
            $brutLigne = $cumul * max(1, $quantite);
            $totalLigne = $this->calculateur->decimal($brutLigne);
            // ⚠ LE MÊME CALCUL QUE CELUI QUI FACTURERA, PAS UN SECOND.
            //
            // `PanierCalculateur::recalculerLigne()` retire cette réduction du montant de la ligne.
            // Tant que l'estimation ne la retirait pas, l'écran annonçait le brut : la caisse disait
            // « Encaisser 8,44 € » puis réclamait 7,60 € au paiement, sans que rien ne nomme la
            // promotion responsable. Le prix bougeait à l'instant où le caissier venait de l'annoncer.
            $montantLigne = $this->calculateur->decimal(
                max(0, $brutLigne - $this->calculateur->reductionPromotions($promotions, $brutLigne)),
            );
        }

        return new PriceQuote(
            produit: (string) $produit->getId(),
            typeTarif: $typeTarif->getNom(),
            prixUnitaire: $prix,
            saison: $saison !== null ? (string) $saison : null,
            canal: $canal->value,
            date: $date->format(\DATE_ATOM),
            promotions: $promotions,
            motif: $this->motif($produit, $typeTarif, $date, $canal, $prix, $saison, $qf),
            options: $groupes,
            quantite: max(1, $quantite),
            totalUnitaire: $totalUnitaire,
            totalLigne: $totalLigne,
            montantLigne: $montantLigne,
        );
    }

    /**
     * Le catalogue d options du produit, **avec le montant deja calcule** et, pour chaque valeur
     * indisponible, la raison.
     *
     * Le filtrage reprend celui d `AjoutLigneHandler::resoudreOptions()` — liaison active, groupe
     * actif, restriction d etablissement (RG-OPT-07/08). La difference est qu ici **on n ecarte pas :
     * on explique**. `claude-H` a nuance sa propre regle pour ce cas : *la question n est pas si
     * l action est possible, c est si l utilisateur a une raison de la chercher.* Un client qui reclame
     * nommement une option que le caissier ne trouve pas l envoie fouiller le parametrage.
     *
     * @param list<string> $retenues
     *
     * @return list<array<string, mixed>>
     */
    private function optionsDuProduit(Produit $produit, ?Etablissement $etablissement, int $baseCentimes, array $retenues): array
    {
        /** @var list<OptionProduit> $liaisons */
        $liaisons = $this->em->getRepository(OptionProduit::class)->findBy(['produit' => $produit, 'actif' => true]);

        $groupes = [];
        foreach ($liaisons as $liaison) {
            $groupe = $liaison->getGroupeOption();
            if ($groupe === null || !$groupe->isActif()) {
                continue;
            }

            $restrictions = $liaison->getEtablissementsRestriction();
            $horsEtablissement = !$restrictions->isEmpty()
                && ($etablissement === null || !$restrictions->contains($etablissement));

            $valeurs = [];
            foreach ($groupe->getValeurs() as $valeur) {
                $impact = $this->impactOption($valeur, $baseCentimes);
                // D54 — l ordre des phrases : d abord le fait sur la DONNEE, ensuite le fait sur
                // l utilisateur ou son etablissement. Inverser envoie chercher un droit manquant.
                $motif = '';
                if (!$valeur->isActif()) {
                    $motif = 'Cette option n est plus proposee.';
                } elseif ($horsEtablissement) {
                    $motif = 'Cette option n est pas proposee dans cet etablissement.';
                }

                $valeurs[] = [
                    'valeurOption' => (string) $valeur->getId(),
                    'libelle' => $valeur->getLibelle(),
                    // Gardes a la demande de claude-H : ils ne servent pas a calculer, ils servent a
                    // EXPLIQUER. « +10 % » repond a « pourquoi » ; « +1,00 EUR » ne repond pas.
                    'impactType' => $valeur->getImpactType()?->value,
                    'impactValeur' => $valeur->getImpactValeur(),
                    // « par unite » et non « unitaire » : une ligne porte une quantite, et
                    // « unitaire par rapport a quoi » est exactement l ambiguite qui produit un
                    // chiffre faux sur le document que le client emporte.
                    'montantParUnite' => $this->calculateur->decimal($impact),
                    'ordreAffichage' => $valeur->getOrdreAffichage(),
                    'disponible' => $motif === '',
                    'motif' => $motif,
                    'retenue' => \in_array((string) $valeur->getId(), $retenues, true),
                ];
            }
            usort($valeurs, static fn (array $a, array $b): int => $a['ordreAffichage'] <=> $b['ordreAffichage']);

            $groupes[] = [
                'groupeOption' => (string) $groupe->getId(),
                'libelle' => $groupe->getLibelle(),
                'modeSelection' => $groupe->getModeSelection()?->value,
                'obligatoire' => $liaison->isObligatoire(),
                'ordreAffichage' => $liaison->getOrdreAffichage(),
                'valeurs' => $valeurs,
            ];
        }
        usort($groupes, static fn (array $a, array $b): int => $a['ordreAffichage'] <=> $b['ordreAffichage']);

        return $groupes;
    }

    /**
     * La phrase que le caissier pourra répéter. **Elle nomme ce qui a décidé du prix**, pas seulement
     * le fait qu'un prix existe : une saison qui s'applique, un quotient familial pris en compte, ou
     * l'absence de grille pour ce couple.
     */
    private function motif(
        Produit $produit,
        TypeTarif $typeTarif,
        \DateTimeImmutable $date,
        Canal $canal,
        ?string $prix,
        ?Uuid $saison,
        ?float $qf,
    ): string {
        if ($prix === null) {
            if (!$typeTarif->estVisibleSur($canal)) {
                return sprintf('Le tarif « %s » n\'est pas vendable sur le canal %s (RG-M1-07).', $typeTarif->getNom(), $canal->value);
            }

            return sprintf(
                'Aucune grille tarifaire pour « %s » au %s : le produit n\'est pas commercialisé à cette date.',
                $typeTarif->getNom(),
                $date->format('d/m/Y'),
            );
        }

        $raisons = [sprintf('tarif « %s »', $typeTarif->getNom())];
        if ($saison !== null) {
            $nom = $this->nomSaison($produit, $saison);
            $raisons[] = $nom !== null ? sprintf('saison « %s »', $nom) : 'saison en cours';
        } else {
            $raisons[] = 'toute l\'année';
        }
        if ($qf !== null) {
            $raisons[] = sprintf('quotient familial %s', rtrim(rtrim(number_format($qf, 2, ',', ' '), '0'), ','));
        }

        return ucfirst(implode(', ', $raisons)) . '.';
    }

    private function nomSaison(Produit $produit, Uuid $saison): ?string
    {
        foreach ($produit->getGrilles() as $grille) {
            $s = $grille->getSaison();
            if ($s !== null && $s->getId()->equals($saison)) {
                return $s->getNom();
            }
        }

        return null;
    }

    /**
     * **L'impact d'une option sur le prix unitaire, en centimes** (RG-OPT-04).
     *
     * Cette formule est **la** raison d'être de cette méthode publique. Elle vivait dans une méthode
     * privée d'`AjoutLigneHandler`, donc hors de portée de l'écran de caisse : `OptionsDisponiblesProvider`
     * déclare lui-même *« lecture seule, sans résolution de prix »*, et l'écran ne pouvait donc lister
     * les options d'un produit **qu'en taisant ce qu'elles coûtent**. Le supplément ne se découvrait
     * qu'après l'ajout au panier.
     *
     * Sans ce point d'entrée, la caisse aurait réimplémenté `ImpactOptionType` côté navigateur — donc
     * une seconde implémentation d'une règle tarifaire, avec exactement l'écart annoncé/facturé que le
     * `PriceQuoter` existe pour supprimer. **Le remède aurait eu la forme de la maladie.**
     *
     * Un pourcentage porte sur le **prix de base résolu**, que l'écran ne connaît pas non plus : c'est
     * la seconde raison pour laquelle le calcul ne peut pas être fait ailleurs qu'ici.
     *
     * `intdiv` sur des centimes entiers, jamais de flottant — la convention du dépôt, `bcmath` n'étant
     * pas installé. Le `100 * 100` n'est pas une coquette : cent pour convertir le pourcentage, cent
     * pour les centimes de sa valeur.
     */
    public function impactOption(ValeurOption $valeur, int $prixBaseCentimes): int
    {
        return $valeur->getImpactType() === ImpactOptionType::Pourcentage
            ? intdiv($prixBaseCentimes * $this->calculateur->centimes($valeur->getImpactValeur()), 100 * 100)
            : $this->calculateur->centimes($valeur->getImpactValeur());
    }

    /**
     * La saison retenue par la grille qui a donné le prix (RG-M1-01).
     *
     * Déplacée ici depuis `AjoutLigneHandler`, où elle était privée : c'est précisément le genre de
     * calcul qu'une estimation aurait dû réécrire, et donc le genre qui diverge.
     *
     * Lue sur la case que le résolveur a RETENUE : elle cherchait auparavant la première case de
     * saison qui contenait la date, qui n'était pas forcément celle du prix appliqué. null pour un
     * prix « toute l'année » (case sans saison).
     */
    public function saison(Produit $produit, TypeTarif $typeTarif, \DateTimeImmutable $date, ?float $qf): ?Uuid
    {
        $grille = $this->resolveurPrix->grilleRetenue($produit, $typeTarif, $date, null, $qf);
        if ($grille === null || $grille->getPrix() === null) {
            return null;
        }

        return $grille->getSaison()?->getId();
    }

    /**
     * Promotions appliquées automatiquement à une ligne (CA-4).
     *
     * @return list<array{id: string, nom: string, type: string, valeur: string|null}>
     */
    public function promotionsAuto(Produit $produit, \DateTimeImmutable $date): array
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

        // Recopie **a l identique** depuis AjoutLigneHandler, y compris ce qui surprend : une
        // promotion dont l eligibilite ne liste aucun produit n est eligible a AUCUN produit — et non
        // a tous, comme on le lirait spontanement. J ai failli « corriger » ce point en
        // deplacant la methode. Une estimation plus permissive que la facturation aurait annonce une
        // remise que la caisse n aurait pas appliquee : exactement le defaut que ce service existe
        // pour empecher, reintroduit par le remede.
        $eligibilite = $promo->getEligibilite() ?? [];
        $produits = $eligibilite['produits'] ?? null;
        if (!\is_array($produits)) {
            return false;
        }

        return \in_array((string) $produit->getId(), array_map('strval', $produits), true);
    }
}
