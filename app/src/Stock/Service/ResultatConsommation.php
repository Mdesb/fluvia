<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Stock\Entity\LotStock;

/**
 * Résultat d'une consommation de couches (§2.1 du plan) : coût total (decimal(12,2)), détail des
 * imputations, et quantité non couverte le cas échéant (rupture de couches, §2.1 « ne devrait pas
 * arriver » — imputation partielle journalisée, non bloquante).
 */
final class ResultatConsommation
{
    /** @param list<ImputationCalculee> $imputations */
    public function __construct(
        public readonly string $coutTotal,
        public readonly array $imputations,
        public readonly ?string $quantiteNonCouverte = null,
    ) {
    }

    /** @return list<LotStock> */
    public function lotsModifies(): array
    {
        return array_map(static fn (ImputationCalculee $i): LotStock => $i->lot, $this->imputations);
    }

    /** Coût unitaire moyen pondéré (utile pour `MouvementStock.coutUnitaireCalcule`), "0.0000" si vide. */
    public function coutUnitaireMoyen(): string
    {
        $quantiteTotale = 0;
        foreach ($this->imputations as $imputation) {
            $quantiteTotale += ArithmetiqueDecimale::versEntier($imputation->quantite, 3);
        }
        if ($quantiteTotale === 0) {
            return '0.0000';
        }
        $coutTotalDixMille = ArithmetiqueDecimale::versEntier($this->coutTotal, 2) * 100000; // vers échelle 7
        $moyenneDixMille = intdiv($coutTotalDixMille, $quantiteTotale); // échelle 4

        return ArithmetiqueDecimale::versDecimal($moyenneDixMille, 4);
    }
}
