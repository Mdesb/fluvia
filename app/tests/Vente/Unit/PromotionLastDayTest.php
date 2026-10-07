<?php

declare(strict_types=1);

namespace App\Tests\Vente\Unit;

use App\Offre\Entity\Produit;
use App\Offre\Entity\Promotion;
use App\Offre\Enum\TypePromotion;
use App\Offre\Service\ResolveurPrix;
use App\Organisation\Entity\Etablissement;
use App\Vente\Service\PanierCalculateur;
use App\Vente\Service\PriceQuoter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Une promotion se compte en jours entiers, à l'heure de l'établissement de la vente, comme une
 * saison (#290) : son premier et son dernier jour s'appliquent de 00:00 à 23:59 à Paris.
 *
 * Mesuré le 07/10/2026 : elle cessait de s'appliquer le dernier jour à 00:00 UTC, l'heure que
 * Doctrine donne à une colonne `date`, et ne s'appliquait le premier jour qu'à partir de 01:00 ou
 * 02:00 à Paris.
 */
final class PromotionLastDayTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function instants(): iterable
    {
        // [fin de la promotion J, instant UTC de la vente, appliquée]. Elle commence le 01/01/2026.
        yield 'J à midi' => ['2026-12-31', '2026-12-31T12:00:00+00:00', true];
        yield 'J à 23:30 à Paris, hiver (22:30 UTC)' => ['2026-12-31', '2026-12-31T22:30:00+00:00', true];
        yield 'J+1 à 00:30 à Paris, hiver (23:30 UTC la veille)' => ['2026-12-31', '2026-12-31T23:30:00+00:00', false];
        yield 'J à 23:30 à Paris, été (21:30 UTC)' => ['2026-08-31', '2026-08-31T21:30:00+00:00', true];
        yield 'J+1 à 00:30 à Paris, été (22:30 UTC la veille)' => ['2026-08-31', '2026-08-31T22:30:00+00:00', false];
        yield 'premier jour à 00:30 à Paris (23:30 UTC la veille)' => ['2026-12-31', '2025-12-31T23:30:00+00:00', true];
        yield 'veille du premier jour à 23:30 à Paris' => ['2026-12-31', '2025-12-31T22:30:00+00:00', false];
    }

    #[DataProvider('instants')]
    public function testPromotionAppliesOnWholeLocalDays(string $fin, string $instant, bool $appliquee): void
    {
        self::assertCount($appliquee ? 1 : 0, $this->promotions('Europe/Paris', $fin, new \DateTimeImmutable($instant)));
    }

    /**
     * Un devis daté « AAAA-MM-JJ » (`?date=`) porte une date civile : elle reste ce jour-là, même à
     * l'ouest de Greenwich. Mesuré le 07/10/2026 : en Martinique, la promotion manquait son premier
     * jour et débordait sur le lendemain du dernier.
     */
    public function testACivilDateIsTheSameDayInMartinique(): void
    {
        foreach (['2025-12-31' => 0, '2026-01-01' => 1, '2026-12-31' => 1, '2027-01-01' => 0] as $jour => $appliquees) {
            self::assertCount($appliquees, $this->promotions('America/Martinique', '2026-12-31', new \DateTimeImmutable($jour)), $jour);
        }
    }

    /** @return list<mixed> les promotions appliquées, pour une promotion du 01/01/2026 à `$fin` */
    private function promotions(string $fuseau, string $fin, \DateTimeImmutable $date): array
    {
        $produit = new Produit();
        $promotion = (new Promotion())->setNom('Promo')->setType(TypePromotion::Pourcentage)->setValeur('10.00')
            ->setDateDebut(new \DateTimeImmutable('2026-01-01'))->setDateFin(new \DateTimeImmutable($fin))
            ->setEligibilite(['produits' => [(string) $produit->getId()]]);

        $depot = $this->createStub(EntityRepository::class);
        $depot->method('findAll')->willReturn([$promotion]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($depot);

        return (new PriceQuoter($em, new ResolveurPrix(), new PanierCalculateur()))
            ->promotionsAuto($produit, $date, (new Etablissement())->setFuseauHoraire($fuseau));
    }
}
