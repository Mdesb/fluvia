<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Unit;

use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Recurrence;
use App\Reservation\Enum\MotifRecurrence;
use App\Reservation\Service\RecurrenceExpansionHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * UNE SÉANCE DE 18:00 RESTE À 18:00 QUAND L'HEURE CHANGE.
 *
 * Les occurrences d'une récurrence se calculent à l'heure de l'établissement, puis se convertissent
 * en UTC (le stockage) une par une. Posées à la même heure UTC, elles glissaient d'une heure au
 * passage à l'heure d'hiver (25/10/2026) et à l'heure d'été (28/03/2027).
 *
 * Règle des heures qui n'existent pas ou existent deux fois, celle d'iCalendar (RFC 5545 §3.3.5) :
 * l'heure dupliquée du passage à l'heure d'hiver prend sa première occurrence (encore à l'heure
 * d'été) ; l'heure manquante du passage à l'heure d'été se lit avec le décalage d'avant le saut,
 * soit une heure plus tard à l'horloge (02:30 devient 03:30). La durée écoulée est conservée.
 */
final class DaylightSavingRecurrenceTest extends TestCase
{
    /** @return iterable<string, array{MotifRecurrence, string, string, string, list<int>, string}> */
    public static function crossings(): iterable
    {
        // motif, premier début (heure de l'établissement), fin, fuseau, jours, occurrence témoin.
        yield 'hebdomadaire, posé en septembre, lu en novembre' => [MotifRecurrence::Hebdomadaire, '2026-09-17 18:00', '2026-11-30', 'Europe/Paris', [], '2026-11-05'];
        yield 'hebdomadaire, posé en février, lu en avril' => [MotifRecurrence::Hebdomadaire, '2027-02-04 18:00', '2027-04-30', 'Europe/Paris', [4], '2027-04-01'];
        yield 'quotidien, à travers le 25/10' => [MotifRecurrence::Quotidien, '2026-10-24 18:00', '2026-10-26', 'Europe/Paris', [], '2026-10-26'];
        yield 'mensuel, à travers le 25/10' => [MotifRecurrence::Mensuel, '2026-10-15 18:00', '2026-11-15', 'Europe/Paris', [], '2026-11-15'];
        // Le fuseau est celui de l'établissement, pas Paris écrit en dur : New York change d'heure
        // le 01/11/2026, une semaine après Paris.
        yield 'hebdomadaire, New York, à travers le 01/11' => [MotifRecurrence::Hebdomadaire, '2026-10-29 18:00', '2026-11-12', 'America/New_York', [], '2026-11-05'];
    }

    /** @param list<int> $days */
    #[DataProvider('crossings')]
    public function testEveryOccurrenceKeepsTheLocalWallClockTime(MotifRecurrence $pattern, string $firstStart, string $end, string $timezone, array $days, string $witnessDay): void
    {
        $zone = new \DateTimeZone($timezone);
        $start = (new \DateTimeImmutable($firstStart, $zone))->setTimezone(new \DateTimeZone('UTC'));

        $occurrences = (new RecurrenceExpansionHandler())->genererOccurrences($start, $start->modify('+1 hour'), $this->recurrence($pattern, $end, $timezone, $days));

        $local = array_map(static fn (array $o): string => $o['debut']->setTimezone($zone)->format('Y-m-d H:i'), $occurrences);
        self::assertContains($witnessDay . ' 18:00', $local, 'La séance témoin, après le changement d\'heure, doit rester à 18:00 à l\'heure de l\'établissement.');
        foreach ($occurrences as $o) {
            self::assertSame('18:00', $o['debut']->setTimezone($zone)->format('H:i'), 'Une occurrence a glissé d\'heure.');
            self::assertSame(0, $o['debut']->getOffset(), 'Une occurrence doit sortir en UTC : Doctrine stocke l\'heure murale de l\'objet, sans conversion.');
            self::assertSame(3600, $o['fin']->getTimestamp() - $o['debut']->getTimestamp());
        }
    }

    public function testTheRepeatedHourTakesItsFirstOccurrence(): void
    {
        $occurrences = $this->dailyAt('2026-10-24 02:30', '2026-10-26');

        self::assertSame(
            ['2026-10-24T00:30:00+00:00', '2026-10-25T00:30:00+00:00', '2026-10-26T01:30:00+00:00'],
            array_map(static fn (array $o): string => $o['debut']->format(\DATE_ATOM), $occurrences),
            'Le 25/10, 02:30 existe deux fois à Paris : la séance prend la première (02:30 heure d\'été, 00:30 UTC).',
        );
        self::assertSame(3600, $occurrences[1]['fin']->getTimestamp() - $occurrences[1]['debut']->getTimestamp(), 'La durée écoulée est conservée.');
    }

    public function testTheMissingHourIsReadWithTheOffsetBeforeTheJump(): void
    {
        $occurrences = $this->dailyAt('2027-03-27 02:30', '2027-03-29');

        self::assertSame(
            ['2027-03-27T01:30:00+00:00', '2027-03-28T01:30:00+00:00', '2027-03-29T00:30:00+00:00'],
            array_map(static fn (array $o): string => $o['debut']->format(\DATE_ATOM), $occurrences),
            'Le 28/03, 02:30 n\'existe pas à Paris : la séance se lit avec le décalage d\'avant le saut, 03:30 heure d\'été (01:30 UTC).',
        );
    }

    public function testAWeeklySundaySessionInTheRepeatedHour(): void
    {
        $zone = new \DateTimeZone('Europe/Paris');
        $start = (new \DateTimeImmutable('2026-10-18 02:30', $zone))->setTimezone(new \DateTimeZone('UTC'));
        $occurrences = (new RecurrenceExpansionHandler())->genererOccurrences($start, $start->modify('+30 minutes'), $this->recurrence(MotifRecurrence::Hebdomadaire, '2026-11-01', 'Europe/Paris', [7]));

        self::assertSame(
            ['2026-10-18T00:30:00+00:00', '2026-10-25T00:30:00+00:00', '2026-11-01T01:30:00+00:00'],
            array_map(static fn (array $o): string => $o['debut']->format(\DATE_ATOM), $occurrences),
        );
    }

    /** @return list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable}> */
    private function dailyAt(string $firstStart, string $end): array
    {
        $start = (new \DateTimeImmutable($firstStart, new \DateTimeZone('Europe/Paris')))->setTimezone(new \DateTimeZone('UTC'));

        return (new RecurrenceExpansionHandler())->genererOccurrences($start, $start->modify('+1 hour'), $this->recurrence(MotifRecurrence::Quotidien, $end, 'Europe/Paris', []));
    }

    /** @param list<int> $days */
    private function recurrence(MotifRecurrence $pattern, string $end, string $timezone, array $days): Recurrence
    {
        return (new Recurrence())
            ->setEtablissement((new Etablissement())->setFuseauHoraire($timezone))
            ->setMotif($pattern)
            ->setFinRecurrence(new \DateTimeImmutable($end))
            ->setJoursSemaine($days === [] ? null : $days);
    }
}
