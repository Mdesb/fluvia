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
    ) {
    }

    /**
     * Le prix applicable, et la raison. Ne crée rien, ne réserve rien, ne consomme aucun stock.
     */
    public function quote(
        Produit $produit,
        TypeTarif $typeTarif,
        \DateTimeImmutable $date,
        Canal $canal = Canal::Guichet,
        ?float $qf = null,
    ): PriceQuote {
        $prix = $this->resolveurPrix->resoudre($produit, $typeTarif, $date, $canal, $qf);
        $saison = $prix === null ? null : $this->saison($produit, $typeTarif, $date, $qf);

        return new PriceQuote(
            produit: (string) $produit->getId(),
            typeTarif: $typeTarif->getNom(),
            prixUnitaire: $prix,
            saison: $saison !== null ? (string) $saison : null,
            canal: $canal->value,
            date: $date->format(\DATE_ATOM),
            promotions: $prix === null ? [] : $this->promotionsAuto($produit, $date),
            motif: $this->motif($produit, $typeTarif, $date, $canal, $prix, $saison, $qf),
        );
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
     * La saison retenue par la grille qui a donné le prix (RG-M1-01).
     *
     * Déplacée ici depuis `AjoutLigneHandler`, où elle était privée : c'est précisément le genre de
     * calcul qu'une estimation aurait dû réécrire, et donc le genre qui diverge.
     */
    public function saison(Produit $produit, TypeTarif $typeTarif, \DateTimeImmutable $date, ?float $qf): ?Uuid
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
