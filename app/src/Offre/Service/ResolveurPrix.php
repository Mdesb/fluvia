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
 * Une case SANS saison vaut toute l'année ; une case d'une saison qui contient la date l'emporte
 * toujours sur elle, quelle que soit sa priorité (la priorité ne départage que des saisons).
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
        return $this->grilleRetenue($produit, $typeTarif, $date, $canal, $qf)?->getPrix();
    }

    /**
     * La case de grille qui fixe le prix (son prix peut être null : non commercialisé). La saison
     * d'une ligne de vente se lit sur elle, pour ne jamais nommer une autre case que celle appliquée.
     */
    public function grilleRetenue(
        Produit $produit,
        TypeTarif $typeTarif,
        \DateTimeImmutable $date,
        ?Canal $canal = null,
        ?float $qf = null,
    ): ?GrilleTarifaire {
        // RG-M1-07 / CA-15 : le tarif doit être visible sur le canal demandé.
        if ($canal !== null && !$typeTarif->estVisibleSur($canal)) {
            return null;
        }

        $meilleure = null;
        foreach ($produit->getGrilles() as $grille) {
            if (!$this->correspond($grille, $typeTarif, $date, $qf)) {
                continue;
            }
            if ($meilleure === null || $this->rangDe($grille) > $this->rangDe($meilleure)) {
                $meilleure = $grille;
            }
        }

        return $meilleure;
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
        // Sans saison : toute l'année.
        $saison = $grille->getSaison();
        if ($saison !== null && (!$saison->isActif() || !$saison->contient($date))) {
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

    /**
     * [a une saison, priorité], comparé élément par élément : une saison l'emporte toujours sur
     * « toute l'année », même de priorité nulle ou négative.
     *
     * @return array{0: int, 1: int}
     */
    private function rangDe(GrilleTarifaire $grille): array
    {
        $saison = $grille->getSaison();

        return $saison === null ? [0, 0] : [1, $saison->getPriorite()];
    }
}
