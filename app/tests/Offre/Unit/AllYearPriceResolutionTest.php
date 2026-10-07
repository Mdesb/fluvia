<?php

declare(strict_types=1);

namespace App\Tests\Offre\Unit;

use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Saison;
use App\Offre\Entity\TypeTarif;
use App\Offre\Service\ResolveurPrix;
use PHPUnit\Framework\TestCase;

/**
 * Un tarif sans saison vaut toute l'année ; un tarif d'une saison précise l'emporte sur lui pendant
 * cette saison, QUELLE QUE SOIT la priorité de la saison et l'ordre des cases (la priorité ne
 * départage que des saisons entre elles).
 */
final class AllYearPriceResolutionTest extends TestCase
{
    public function testSeasonWinsOverAllYearWhateverTheOrderAndPriority(): void
    {
        $resolveur = new ResolveurPrix();
        $tarif = (new TypeTarif())->setNom('Plein')->setVisibiliteCanal([]);

        foreach ([0, -5] as $priorite) {
            $ete = (new Saison())->setNom('Été')
                ->setDateDebut(new \DateTimeImmutable('2026-07-01'))
                ->setDateFin(new \DateTimeImmutable('2026-08-31'))
                ->setPriorite($priorite);

            // La case « toute l'année » est ajoutée EN PREMIER : à priorité égale, l'ancien
            // départage gardait la première rencontrée.
            $produit = new Produit();
            $produit->addGrille((new GrilleTarifaire())->setTypeTarif($tarif)->setPrix('10.00'));
            $produit->addGrille((new GrilleTarifaire())->setTypeTarif($tarif)->setSaison($ete)->setPrix('8.00'));

            self::assertSame('8.00', $resolveur->resoudre($produit, $tarif, new \DateTimeImmutable('2026-07-15')), 'priorité ' . $priorite);
            self::assertSame('10.00', $resolveur->resoudre($produit, $tarif, new \DateTimeImmutable('2026-03-15')), 'priorité ' . $priorite);
            self::assertSame($ete, $resolveur->grilleRetenue($produit, $tarif, new \DateTimeImmutable('2026-07-15'))?->getSaison());
        }
    }

    public function testInactiveSeasonFallsBackToAllYear(): void
    {
        $resolveur = new ResolveurPrix();
        $tarif = (new TypeTarif())->setNom('Plein')->setVisibiliteCanal([]);
        $ete = (new Saison())->setNom('Été')
            ->setDateDebut(new \DateTimeImmutable('2026-07-01'))
            ->setDateFin(new \DateTimeImmutable('2026-08-31'))
            ->setActif(false);

        $produit = new Produit();
        $produit->addGrille((new GrilleTarifaire())->setTypeTarif($tarif)->setSaison($ete)->setPrix('8.00'));
        $produit->addGrille((new GrilleTarifaire())->setTypeTarif($tarif)->setPrix('10.00'));

        self::assertSame('10.00', $resolveur->resoudre($produit, $tarif, new \DateTimeImmutable('2026-07-15')));
    }
}
