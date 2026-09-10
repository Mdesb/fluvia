<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use App\Vente\Enum\RemiseType;

/**
 * Recalcul instantané du panier (US-L2-03, cahier M2-§7). Le montant d'une ligne =
 * (prixUnitaire × qté) − remise de ligne − promotions ; le total de la vente et le reste dû
 * découlent des lignes et des paiements. Tous les montants sont manipulés en centimes pour éviter
 * les erreurs de flottant, puis reformatés en decimal(10,2).
 */
final class PanierCalculateur
{
    /** Recalcule le montant d'une ligne (remise + promotions comprises) en centimes puis en decimal. */
    public function recalculerLigne(LigneVente $ligne): void
    {
        // App\OptionProduit (RG-OPT-04) : l'impact unitaire des options s'ajoute au prix de base avant
        // remise/promotion. '0.00' par défaut ⇒ centimes('0.00') === 0, calcul inchangé sans option.
        $brut = ($this->centimes($ligne->getPrixUnitaire()) + $this->centimes($ligne->getImpactOptionsUnitaire())) * $ligne->getQuantite();

        $remise = 0;
        if ($ligne->getRemiseLigne() !== null && $ligne->getRemiseType() !== null) {
            $remise = $ligne->getRemiseType() === RemiseType::Pourcentage
                ? intdiv($brut * $this->centimes($ligne->getRemiseLigne()), 100 * 100)
                : $this->centimes($ligne->getRemiseLigne());
        }

        $promo = $this->reductionPromotions($ligne->getPromotionsAppliquees() ?? [], $brut - $remise);

        $montant = max(0, $brut - $remise - $promo);
        $ligne->setMontantLigne($this->decimal($montant));
    }

    /** Recalcule les totaux de la vente à partir de ses lignes et le reste dû à partir des paiements. */
    public function recalculerVente(Vente $vente): void
    {
        $total = 0;
        $remises = 0;
        foreach ($vente->getLignes() as $ligne) {
            $brut = ($this->centimes($ligne->getPrixUnitaire()) + $this->centimes($ligne->getImpactOptionsUnitaire())) * $ligne->getQuantite();
            $total += $this->centimes($ligne->getMontantLigne());
            $remises += max(0, $brut - $this->centimes($ligne->getMontantLigne()));
        }

        $paye = 0;
        foreach ($vente->getPaiements() as $paiement) {
            // Le rendu de monnaie n'entame pas le montant imputé au dû.
            $paye += $this->centimes($paiement->getMontant()) - $this->centimes($paiement->getRendu());
        }

        $vente->setTotal($this->decimal($total));
        $vente->setTotalRemises($this->decimal($remises));
        $vente->setResteAPayer($this->decimal(max(0, $total - $paye)));
    }

    /** Somme imputée (hors rendu) déjà réglée sur la vente, en centimes. */
    public function montantRegle(Vente $vente): int
    {
        $paye = 0;
        foreach ($vente->getPaiements() as $paiement) {
            $paye += $this->centimes($paiement->getMontant()) - $this->centimes($paiement->getRendu());
        }

        return $paye;
    }

    public function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    public function decimal(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
    }

    /**
     * Réduction issue des promotions retenues (montant/pourcentage), en centimes. Les données de
     * promotion sont figées sur la ligne au moment de l'ajout (US-L2-03).
     *
     * ⚠ **PUBLIQUE, ET C'EST TOUT L'INTÉRÊT.** Elle vivait ici en privé — donc hors de portée de
     * l'estimation que la caisse demande AVANT qu'une vente existe. L'estimation ignorait donc les
     * promotions automatiques : l'écran annonçait 8,44 €, le paiement réclamait 7,60 €, et **rien ne
     * nommait l'écart**. Le caissier voyait le prix bouger au moment précis où il l'annonce à voix
     * haute, sans pouvoir dire pourquoi.
     *
     * Réécrire la formule côté estimation aurait produit deux implémentations d'une même règle
     * tarifaire — exactement ce que `PriceQuoter` existe pour empêcher.
     *
     * @param list<mixed> $promotions figées sur la ligne, ou rendues par `PriceQuoter::promotionsAuto()`
     */
    public function reductionPromotions(array $promotions, int $base): int
    {
        $reduction = 0;
        foreach ($promotions as $promo) {
            if (!\is_array($promo)) {
                continue;
            }
            $type = $promo['type'] ?? null;
            $valeur = isset($promo['valeur']) ? $this->centimes((string) $promo['valeur']) : 0;
            if ($type === 'pourcentage') {
                $reduction += intdiv($base * $valeur, 100 * 100);
            } elseif ($type === 'montant') {
                $reduction += $valeur;
            }
        }

        return $reduction;
    }
}
