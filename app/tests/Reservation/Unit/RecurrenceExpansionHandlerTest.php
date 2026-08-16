<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Unit;

use App\Reservation\Entity\Recurrence;
use App\Reservation\Enum\MotifRecurrence;
use App\Reservation\Service\RecurrenceExpansionHandler;
use PHPUnit\Framework\TestCase;

/** Génération des occurrences d'une récurrence (RG-M5-07). */
final class RecurrenceExpansionHandlerTest extends TestCase
{
    public function testGenerationOccurrencesHebdo(): void
    {
        $handler = new RecurrenceExpansionHandler();

        $recurrence = (new Recurrence())->setMotif(MotifRecurrence::Hebdomadaire)
            ->setFinRecurrence(new \DateTimeImmutable('2026-09-28'))
            ->setJoursSemaine([1]); // tous les lundis

        $premierDebut = new \DateTimeImmutable('2026-09-07T10:00:00'); // un lundi.
        $premierFin = new \DateTimeImmutable('2026-09-07T11:00:00');

        $occurrences = $handler->genererOccurrences($premierDebut, $premierFin, $recurrence);

        // Lundis 7, 14, 21, 28 septembre 2026 = 4 occurrences.
        self::assertCount(4, $occurrences);
        foreach ($occurrences as $occurrence) {
            self::assertSame('10:00:00', $occurrence['debut']->format('H:i:s'));
            self::assertSame(3600, $occurrence['fin']->getTimestamp() - $occurrence['debut']->getTimestamp());
            self::assertSame('1', $occurrence['debut']->format('N'), 'Chaque occurrence tombe un lundi.');
        }
    }

    public function testGenerationOccurrencesQuotidien(): void
    {
        $handler = new RecurrenceExpansionHandler();

        $recurrence = (new Recurrence())->setMotif(MotifRecurrence::Quotidien)->setFinRecurrence(new \DateTimeImmutable('2026-09-04'));
        $premierDebut = new \DateTimeImmutable('2026-09-01T08:00:00');
        $premierFin = new \DateTimeImmutable('2026-09-01T09:00:00');

        $occurrences = $handler->genererOccurrences($premierDebut, $premierFin, $recurrence);

        self::assertCount(4, $occurrences); // 1, 2, 3, 4 septembre.
    }

    public function testGenerationOccurrencesMensuel(): void
    {
        $handler = new RecurrenceExpansionHandler();

        $recurrence = (new Recurrence())->setMotif(MotifRecurrence::Mensuel)->setFinRecurrence(new \DateTimeImmutable('2026-11-15'));
        $premierDebut = new \DateTimeImmutable('2026-09-15T14:00:00');
        $premierFin = new \DateTimeImmutable('2026-09-15T15:00:00');

        $occurrences = $handler->genererOccurrences($premierDebut, $premierFin, $recurrence);

        self::assertCount(3, $occurrences); // 15 sept, 15 oct, 15 nov.
    }
}
