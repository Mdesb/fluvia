<?php

declare(strict_types=1);

namespace App\Offre\Service;

use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\Canal;

/**
 * Résolveur de prix (RG-M1-01/06/07). Le prix est déterminé par produit × type de tarif × saison
 * (+ tranche de QF). En cas de chevauchement de saisons, la priorité supérieure l'emporte (CA-12).
 * Un prix null vaut « non commercialisé » (CA-5). Un tarif non visible sur le canal demandé n'est
 * pas retenu (RG-M1-07 / CA-15).
 */
final class ResolveurPrix
{
    /**
     * Résout le prix d'un produit pour un type de tarif, à une date et un canal donnés.
     * Renvoie null si non commercialisé ou tarif non visible sur le canal.
     *
     * @param float|null $qf valeur de quotient familial éventuelle
     */
    public function resoudre(
        Produit $produit,
        TypeTarif $typeTarif,
        \DateTimeImmutable $date,
        ?Canal $canal = null,
        ?float $qf = null,
    ): ?string {
        // RG-M1-07 / CA-15 : le tarif doit être visible sur le canal demandé.
        if ($canal !== null && !$typeTarif->estVisibleSur($canal)) {
            return null;
        }

        $meilleure = null;
        foreach ($produit->getGrilles() as $grille) {
            if (!$this->correspond($grille, $typeTarif, $date, $qf)) {
                continue;
            }
            if ($meilleure === null || $this->prioriteDe($grille) > $this->prioriteDe($meilleure)) {
                $meilleure = $grille;
            }
        }

        return $meilleure?->getPrix();
    }

    /**
     * Indique si un produit est commercialisé (au moins un prix non null) pour un type de tarif
     * à une date/canal donnés.
     */
    public function estCommercialise(
        Produit $produit,
        TypeTarif $typeTarif,
        \DateTimeImmutable $date,
        ?Canal $canal = null,
    ): bool {
        return $this->resoudre($produit, $typeTarif, $date, $canal) !== null;
    }

    private function correspond(GrilleTarifaire $grille, TypeTarif $typeTarif, \DateTimeImmutable $date, ?float $qf): bool
    {
        if ($grille->getTypeTarif() === null || !$grille->getTypeTarif()->getId()->equals($typeTarif->getId())) {
            return false;
        }
        $saison = $grille->getSaison();
        if ($saison === null || !$saison->isActif() || !$saison->contient($date)) {
            return false;
        }
        $tranche = $grille->getTrancheQf();
        if ($tranche !== null) {
            if ($qf === null || !$tranche->contient($qf)) {
                return false;
            }
        }

        return true;
    }

    private function prioriteDe(GrilleTarifaire $grille): int
    {
        return $grille->getSaison()?->getPriorite() ?? 0;
    }
}
