<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Unit;

use App\Subscription\Exception\InvalidPeriodException;
use App\Subscription\Service\ProrationCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CA-4 — prorata d'une option ajoutée en cours de période.
 *
 * Ces tests portent sur des bornes, pas sur des cas moyens : premier jour, dernier jour, mois de 28 et
 * de 31 jours, prix nul, période inversée. C'est là que les erreurs d'un centime se logent, et un
 * centime d'écart sur un prélèvement se remarque bien plus qu'un bug d'affichage.
 */
final class ProrationCalculatorTest extends TestCase
{
    private const PRIX_MENSUEL = 3000; // 30,00 €

    private ProrationCalculator $calcul;

    protected function setUp(): void
    {
        $this->calcul = new ProrationCalculator();
    }

    public function testActiveDesLePremierJourLaPeriodeEstDueEnEntier(): void
    {
        self::assertSame(
            self::PRIX_MENSUEL,
            $this->prorata(self::PRIX_MENSUEL, '2026-09-01', '2026-09-01', '2026-10-01'),
        );
    }

    /** Une date antérieure au début ne fait pas payer davantage : le prix plein est un plafond. */
    public function testActiveAvantLaPeriodeNeDepassePasLePrixPlein(): void
    {
        self::assertSame(
            self::PRIX_MENSUEL,
            $this->prorata(self::PRIX_MENSUEL, '2026-08-14', '2026-09-01', '2026-10-01'),
        );
    }

    /** Après la fin, rien n'est dû : l'option sera facturée sur la période suivante, pas sur celle-ci. */
    public function testActiveApresLaFinNeDoitRien(): void
    {
        self::assertSame(0, $this->prorata(self::PRIX_MENSUEL, '2026-10-01', '2026-09-01', '2026-10-01'));
        self::assertSame(0, $this->prorata(self::PRIX_MENSUEL, '2026-10-15', '2026-09-01', '2026-10-01'));
    }

    /** Septembre a 30 jours : activer le 16 laisse 15 jours, soit exactement la moitié. */
    public function testMoitieDeMoisSurUnMoisDeTrenteJours(): void
    {
        self::assertSame(1500, $this->prorata(self::PRIX_MENSUEL, '2026-09-16', '2026-09-01', '2026-10-01'));
    }

    /** Le dernier jour reste dû en entier : on compte des jours, pas des heures. */
    public function testLeDernierJourEstDu(): void
    {
        self::assertSame(100, $this->prorata(self::PRIX_MENSUEL, '2026-09-30', '2026-09-01', '2026-10-01'));
    }

    /**
     * Deux activations le même jour à des heures différentes doivent coûter le même prix.
     *
     * Sans normalisation à minuit, un client activant à 23 h paierait moins qu'un autre à 8 h — un
     * écart indéfendable au téléphone, et invisible en revue de code.
     */
    public function testLHeureDeLaJourneeNeChangeRien(): void
    {
        $matin = $this->calcul->forPartialPeriod(
            self::PRIX_MENSUEL,
            new \DateTimeImmutable('2026-09-16 08:00:00'),
            new \DateTimeImmutable('2026-09-01 00:00:00'),
            new \DateTimeImmutable('2026-10-01 00:00:00'),
        );
        $soir = $this->calcul->forPartialPeriod(
            self::PRIX_MENSUEL,
            new \DateTimeImmutable('2026-09-16 23:59:59'),
            new \DateTimeImmutable('2026-09-01 00:00:00'),
            new \DateTimeImmutable('2026-10-01 00:00:00'),
        );

        self::assertSame($matin, $soir);
    }

    /** La longueur réelle du mois est prise en compte, pas une approximation à 30 jours. */
    #[DataProvider('moisDeLongueursDifferentes')]
    public function testLaLongueurReelleDuMoisEstUtilisee(
        string $debut,
        string $fin,
        string $activation,
        int $attendu,
    ): void {
        self::assertSame($attendu, $this->prorata(self::PRIX_MENSUEL, $activation, $debut, $fin));
    }

    /** @return iterable<string, array{string, string, string, int}> */
    public static function moisDeLongueursDifferentes(): iterable
    {
        // Février 2026 : 28 jours. Activer le 15 laisse 14 jours, soit la moitié pile.
        yield 'février, 28 jours, moitié' => ['2026-02-01', '2026-03-01', '2026-02-15', 1500];
        // Janvier : 31 jours. Activer le 17 laisse 15 jours.
        yield 'janvier, 31 jours' => ['2026-01-01', '2026-02-01', '2026-01-17', 1452];
        // Un mois bissextil : février 2028 compte 29 jours.
        yield 'février bissextile, 29 jours' => ['2028-02-01', '2028-03-01', '2028-02-16', 1448];
    }

    /** Plus on active tard, moins on paie — jamais l'inverse. */
    public function testLeMontantDecroitStrictementAvecLaDateDActivation(): void
    {
        $precedent = self::PRIX_MENSUEL + 1;

        for ($jour = 1; $jour <= 30; ++$jour) {
            $montant = $this->prorata(
                self::PRIX_MENSUEL,
                sprintf('2026-09-%02d', $jour),
                '2026-09-01',
                '2026-10-01',
            );

            self::assertLessThanOrEqual($precedent, $montant, sprintf('au jour %d', $jour));
            self::assertGreaterThanOrEqual(0, $montant);
            self::assertLessThanOrEqual(self::PRIX_MENSUEL, $montant);
            $precedent = $montant;
        }
    }

    public function testUnPrixNulNeProduitAucunMontant(): void
    {
        self::assertSame(0, $this->prorata(0, '2026-09-16', '2026-09-01', '2026-10-01'));
    }

    /** Un prix négatif n'a pas de sens ici : on ne fabrique pas un avoir par accident. */
    public function testUnPrixNegatifNeProduitAucunMontant(): void
    {
        self::assertSame(0, $this->prorata(-500, '2026-09-16', '2026-09-01', '2026-10-01'));
    }

    public function testPeriodeInverseeRefusee(): void
    {
        $this->expectException(InvalidPeriodException::class);

        $this->prorata(self::PRIX_MENSUEL, '2026-09-16', '2026-10-01', '2026-09-01');
    }

    public function testPeriodeVideRefusee(): void
    {
        $this->expectException(InvalidPeriodException::class);

        $this->prorata(self::PRIX_MENSUEL, '2026-09-16', '2026-09-01', '2026-09-01');
    }

    private function prorata(int $prixCents, string $activation, string $debut, string $fin): int
    {
        return $this->calcul->forPartialPeriod(
            $prixCents,
            new \DateTimeImmutable($activation),
            new \DateTimeImmutable($debut),
            new \DateTimeImmutable($fin),
        );
    }
}
