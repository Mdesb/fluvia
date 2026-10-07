<?php

declare(strict_types=1);

namespace App\Tests\Offre\Unit;

use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Saison;
use App\Offre\Entity\TypeTarif;
use App\Offre\Service\ResolveurPrix;
use App\Organisation\Entity\Etablissement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Une saison se compte en jours entiers, à l'heure de l'établissement : son premier et son dernier
 * jour se vendent de 00:00 à 23:59 à Paris, la veille et le lendemain non.
 *
 * Mesuré le 07/10/2026 : la vente s'arrêtait le dernier jour à 00:00 UTC, l'heure que Doctrine donne
 * à une colonne `date`, et commençait le premier jour à 01:00 ou 02:00 à Paris.
 */
final class SeasonLastDayTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function instants(): iterable
    {
        // [fin de saison J, instant UTC de la vente, vendable]. La saison commence le 01/01/2026.
        yield 'J à midi' => ['2026-12-31', '2026-12-31T12:00:00+00:00', true];
        yield 'J à 23:30 à Paris, hiver (22:30 UTC)' => ['2026-12-31', '2026-12-31T22:30:00+00:00', true];
        yield 'J+1 à 00:30 à Paris, hiver (23:30 UTC la veille)' => ['2026-12-31', '2026-12-31T23:30:00+00:00', false];
        yield 'J à 23:30 à Paris, été (21:30 UTC)' => ['2026-08-31', '2026-08-31T21:30:00+00:00', true];
        yield 'J+1 à 00:30 à Paris, été (22:30 UTC la veille)' => ['2026-08-31', '2026-08-31T22:30:00+00:00', false];
        yield 'premier jour à 00:30 à Paris (23:30 UTC la veille)' => ['2026-12-31', '2025-12-31T23:30:00+00:00', true];
        yield 'veille du premier jour à 23:30 à Paris' => ['2026-12-31', '2025-12-31T22:30:00+00:00', false];
    }

    #[DataProvider('instants')]
    public function testSeasonIsSellableOnWholeLocalDays(string $fin, string $instant, bool $vendable): void
    {
        $saison = $this->saison()->setDateFin(new \DateTimeImmutable($fin));

        self::assertSame($vendable ? '9.00' : null, $this->prix($saison, $instant));
    }

    /** Une saison « chaque année » lit aussi le jour de Paris : 00:30 le 01/01 n'est plus décembre. */
    public function testAnnualSeasonReadsTheLocalDay(): void
    {
        $decembre = $this->saison()->setDateDebut(new \DateTimeImmutable('2026-12-01'))
            ->setDateFin(new \DateTimeImmutable('2026-12-31'))->setRecurrenceAnnuelle(true);

        self::assertSame('9.00', $this->prix($decembre, '2027-12-31T22:30:00+00:00'));
        self::assertNull($this->prix($decembre, '2027-12-31T23:30:00+00:00'));
    }

    private function saison(): Saison
    {
        return (new Saison())->setNom('Saison')->setDateDebut(new \DateTimeImmutable('2026-01-01'))
            ->setEtablissement((new Etablissement())->setFuseauHoraire('Europe/Paris'));
    }

    private function prix(Saison $saison, string $instant): ?string
    {
        $tarif = (new TypeTarif())->setNom('Plein')->setVisibiliteCanal([]);
        $produit = (new Produit())->addGrille((new GrilleTarifaire())->setTypeTarif($tarif)->setSaison($saison)->setPrix('9.00'));

        return (new ResolveurPrix())->resoudre($produit, $tarif, new \DateTimeImmutable($instant));
    }
}
