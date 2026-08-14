<?php

declare(strict_types=1);

namespace App\Tests\Offre\Unit;

use App\Offre\Entity\CarteMultiEntrees;
use App\Offre\Entity\Formule;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Saison;
use App\Offre\Entity\ServiceInclus;
use App\Offre\Entity\TrancheQuotientFamilial;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\Canal;
use App\Offre\Enum\PeriodeQuota;
use App\Offre\Enum\PeriodiciteFormule;
use App\Offre\Service\ResolveurFacettes;
use App\Offre\Service\ResolveurPrix;
use App\Offre\Service\SimulateurQuota;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires des services M1 : facettes (CA-3), résolveur de prix (CA-12, CA-15),
 * quota semaine calendaire (CA-7), résolution de tranche QF (CA-6).
 */
final class ServicesOffreTest extends TestCase
{
    /** CA-3 / RG-M1-02 — Une facette non portée par le type purge la saisie orpheline. */
    public function testCa3FacettesEtPurgeOrphelins(): void
    {
        $resolveur = new ResolveurFacettes();

        $typeCarnet = (new TypeProduit())->setCode('carte')->setFacettes([TypeProduit::FACETTE_CARNET]);
        $typeBillet = (new TypeProduit())->setCode('entree')->setFacettes([TypeProduit::FACETTE_BILLET]);

        self::assertTrue($resolveur->facetteVisible($typeCarnet, TypeProduit::FACETTE_CARNET));
        self::assertFalse($resolveur->facetteVisible($typeBillet, TypeProduit::FACETTE_CARNET));

        // Un produit de type billet ne doit pas conserver une carte (facette carnet non visible).
        $produit = (new Produit())->setType($typeBillet)->setCarte(new CarteMultiEntrees());
        self::assertNotNull($produit->getCarte());
        $resolveur->purgerOrphelins($produit);
        self::assertNull($produit->getCarte(), 'La saisie orpheline (carte) doit être purgée.');
    }

    /** CA-12 / RG-M1-06 — Sur chevauchement de saisons, la priorité supérieure l'emporte. */
    public function testCa12ResolutionParPrioriteDeSaison(): void
    {
        $resolveur = new ResolveurPrix();
        $tarif = (new TypeTarif())->setNom('Plein')->setVisibiliteCanal([]);

        $basse = (new Saison())->setNom('Basse')
            ->setDateDebut(new \DateTimeImmutable('2026-01-01'))
            ->setDateFin(new \DateTimeImmutable('2026-12-31'))
            ->setPriorite(0);
        $haute = (new Saison())->setNom('Promo été')
            ->setDateDebut(new \DateTimeImmutable('2026-07-01'))
            ->setDateFin(new \DateTimeImmutable('2026-08-31'))
            ->setPriorite(10);

        $produit = new Produit();
        $produit->addGrille((new GrilleTarifaire())->setTypeTarif($tarif)->setSaison($basse)->setPrix('10.00'));
        $produit->addGrille((new GrilleTarifaire())->setTypeTarif($tarif)->setSaison($haute)->setPrix('7.00'));

        // En juillet, les deux saisons matchent : la priorité supérieure (haute) donne 7.00.
        $prixJuillet = $resolveur->resoudre($produit, $tarif, new \DateTimeImmutable('2026-07-15'));
        self::assertSame('7.00', $prixJuillet);

        // En mars, seule la basse s'applique : 10.00.
        $prixMars = $resolveur->resoudre($produit, $tarif, new \DateTimeImmutable('2026-03-15'));
        self::assertSame('10.00', $prixMars);
    }

    /** CA-15 / RG-M1-07 — Un tarif « guichet uniquement » n'apparaît pas sur le canal en ligne. */
    public function testCa15VisibiliteCanal(): void
    {
        $resolveur = new ResolveurPrix();
        $tarifGuichet = (new TypeTarif())->setNom('Guichet')->setVisibiliteCanal(['guichet']);

        self::assertTrue($tarifGuichet->estVisibleSur(Canal::Guichet));
        self::assertFalse($tarifGuichet->estVisibleSur(Canal::EnLigne));

        $saison = (new Saison())->setNom('S')
            ->setDateDebut(new \DateTimeImmutable('2026-01-01'))
            ->setDateFin(new \DateTimeImmutable('2026-12-31'));
        $produit = new Produit();
        $produit->addGrille((new GrilleTarifaire())->setTypeTarif($tarifGuichet)->setSaison($saison)->setPrix('4.00'));

        $date = new \DateTimeImmutable('2026-05-01');
        self::assertSame('4.00', $resolveur->resoudre($produit, $tarifGuichet, $date, Canal::Guichet));
        self::assertNull($resolveur->resoudre($produit, $tarifGuichet, $date, Canal::EnLigne));
    }

    /** CA-5 — Une case de grille vide (prix null) vaut « non commercialisé » (≠ gratuit). */
    public function testCa5PrixNullNonCommercialise(): void
    {
        $resolveur = new ResolveurPrix();
        $tarif = (new TypeTarif())->setNom('Plein')->setVisibiliteCanal([]);
        $saison = (new Saison())->setNom('S')
            ->setDateDebut(new \DateTimeImmutable('2026-01-01'))
            ->setDateFin(new \DateTimeImmutable('2026-12-31'));

        $produit = new Produit();
        $produit->addGrille((new GrilleTarifaire())->setTypeTarif($tarif)->setSaison($saison)->setPrix(null));

        $prix = $resolveur->resoudre($produit, $tarif, new \DateTimeImmutable('2026-05-01'));
        self::assertNull($prix, 'Prix null = non commercialisé.');
        self::assertFalse($resolveur->estCommercialise($produit, $tarif, new \DateTimeImmutable('2026-05-01')));
    }

    /** CA-7 / RG-M1-12 — Quota en semaine calendaire (lundi→dimanche), sans report. */
    public function testCa7QuotaSemaineCalendaire(): void
    {
        $simulateur = new SimulateurQuota();

        // 2026-08-12 est un mercredi ; la fenêtre doit être lun 10 → dim 16 août.
        [$lundi, $dimanche] = $simulateur->fenetreSemaine(new \DateTimeImmutable('2026-08-12 14:00:00'));
        self::assertSame('2026-08-10', $lundi->format('Y-m-d'));
        self::assertSame('1', $lundi->format('N'));
        self::assertSame('2026-08-16', $dimanche->format('Y-m-d'));
        self::assertSame('7', $dimanche->format('N'));

        $service = (new ServiceInclus())->setQuota(2)->setPeriode(PeriodeQuota::SemaineCalendaire);

        // Deux consommations cette semaine : quota épuisé.
        $conso = [new \DateTimeImmutable('2026-08-10 09:00'), new \DateTimeImmutable('2026-08-11 09:00')];
        self::assertSame(0, $simulateur->quotaRestant($service, new \DateTimeImmutable('2026-08-12'), $conso));

        // La semaine suivante : remise à zéro, sans report (quota plein de nouveau).
        self::assertSame(2, $simulateur->quotaRestant($service, new \DateTimeImmutable('2026-08-19'), $conso));

        // Simulation semaine type d'une formule.
        $formule = (new Formule())->setPeriodicite(PeriodiciteFormule::Mensuel);
        $formule->addServiceInclus($service);
        $droits = $simulateur->simulerSemaineType($formule);
        self::assertCount(1, $droits);
        self::assertSame(2, $droits[0]['quotaSemaine']);
    }

    /** CA-6 — Une valeur de QF résout vers une et une seule tranche (bornes [min, max[). */
    public function testCa6ResolutionUniqueTrancheQf(): void
    {
        $t1 = (new TrancheQuotientFamilial())->setBorneMin('0')->setBorneMax('600');
        $t2 = (new TrancheQuotientFamilial())->setBorneMin('600')->setBorneMax('1200');

        // 600 appartient à t2 uniquement (borne max exclue de t1, incluse comme min de t2).
        self::assertFalse($t1->contient(600.0));
        self::assertTrue($t2->contient(600.0));

        // 599.99 dans t1 seulement.
        self::assertTrue($t1->contient(599.99));
        self::assertFalse($t2->contient(599.99));

        $resolues = array_filter([$t1, $t2], static fn (TrancheQuotientFamilial $t): bool => $t->contient(300.0));
        self::assertCount(1, $resolues, 'Une valeur de QF doit résoudre vers exactement une tranche.');
    }
}
