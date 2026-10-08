<?php

declare(strict_types=1);

namespace App\Tests\Acces\Unit;

use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Service\CardExpiryCalculator;
use App\Acces\Service\ResolveurMarges;
use App\Offre\Entity\CarteMultiEntrees;
use App\Organisation\Entity\Etablissement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La date butoir d'une carte multi-entrées est son DERNIER JOUR UTILISABLE, jusqu'à minuit à
 * l'heure de l'établissement (décision de Maxime du 07/10/2026).
 *
 * Mesuré le 07/10/2026 : l'échéance valait la date butoir à 00:00 UTC, l'heure que Doctrine donne
 * à une colonne `date`. Le tourniquet refusait donc la carte dès 01:00 ou 02:00 à Paris le jour
 * butoir, et dès 20:00 la veille en Martinique.
 */
final class CardDeadlineLastDayTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, bool}> */
    public static function passages(): iterable
    {
        // [fuseau, date butoir, instant UTC du passage, accepté].
        yield 'Paris, le jour butoir à 01:30 (hiver)' => ['Europe/Paris', '2026-12-31', '2026-12-31T00:30:00+00:00', true];
        yield 'Paris, le jour butoir à 23:30 (hiver)' => ['Europe/Paris', '2026-12-31', '2026-12-31T22:30:00+00:00', true];
        yield 'Paris, le lendemain à 00:30 (hiver)' => ['Europe/Paris', '2026-12-31', '2026-12-31T23:30:00+00:00', false];
        yield 'Paris, le jour butoir à 23:30 (été)' => ['Europe/Paris', '2027-08-31', '2027-08-31T21:30:00+00:00', true];
        yield 'Paris, le lendemain à 00:30 (été)' => ['Europe/Paris', '2027-08-31', '2027-08-31T22:30:00+00:00', false];
        yield 'Martinique, le jour butoir à 23:30' => ['America/Martinique', '2026-12-31', '2027-01-01T03:30:00+00:00', true];
        yield 'Martinique, le lendemain à 00:30' => ['America/Martinique', '2026-12-31', '2027-01-01T04:30:00+00:00', false];
    }

    #[DataProvider('passages')]
    public function testTheTurnstileAcceptsTheCardUntilMidnightOfItsDeadline(string $fuseau, string $butoir, string $instant, bool $accepte): void
    {
        $etablissement = (new Etablissement())->setFuseauHoraire($fuseau);
        // La date butoir comme Doctrine rend une colonne `date` : 00:00 dans le fuseau du serveur.
        $carte = (new CarteMultiEntrees())->setDateButoir(new \DateTimeImmutable($butoir));
        $fin = (new CardExpiryCalculator())->calculer($carte, null, new \DateTimeImmutable('2026-06-01T10:00:00+00:00'), $etablissement);

        $droit = (new DroitAcces())->setFenetreFin($fin);

        self::assertSame($accepte, (new ResolveurMarges())->estDansMarges($droit, new Equipement(), new \DateTimeImmutable($instant)));
    }

    /** Une durée plus courte que la date butoir reste un instant : elle n'est pas arrondie au jour. */
    public function testADurationShorterThanTheDeadlineIsKept(): void
    {
        $carte = (new CarteMultiEntrees())->setValiditeDuree(new \DateInterval('P1M'))->setDateButoir(new \DateTimeImmutable('2026-12-31'));
        $paris = (new Etablissement())->setFuseauHoraire('Europe/Paris');

        self::assertEquals(
            new \DateTimeImmutable('2026-07-01T10:00:00+00:00'),
            (new CardExpiryCalculator())->calculer($carte, null, new \DateTimeImmutable('2026-06-01T10:00:00+00:00'), $paris),
        );
    }
}
