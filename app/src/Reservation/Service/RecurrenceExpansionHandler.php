<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Recurrence;
use App\Reservation\Enum\MotifRecurrence;

/**
 * Génère les occurrences d'une récurrence (RG-M5-07) : hebdomadaire (jours de semaine donnés, ou le
 * jour du premier créneau à défaut), quotidien, mensuel (même jour du mois). Chaque occurrence
 * conserve la durée du premier créneau.
 */
final class RecurrenceExpansionHandler
{
    /**
     * @return list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable}>
     */
    public function genererOccurrences(\DateTimeImmutable $premierDebut, \DateTimeImmutable $premierFin, Recurrence $recurrence): array
    {
        $dureeSecondes = $premierFin->getTimestamp() - $premierDebut->getTimestamp();
        $finBorne = $recurrence->getFinRecurrence()->setTime(23, 59, 59);

        return match ($recurrence->getMotif()) {
            MotifRecurrence::Quotidien => $this->parPas($premierDebut, $finBorne, $dureeSecondes, '+1 day'),
            MotifRecurrence::Mensuel => $this->parPas($premierDebut, $finBorne, $dureeSecondes, '+1 month'),
            MotifRecurrence::Hebdomadaire => $this->hebdomadaire($premierDebut, $finBorne, $dureeSecondes, $recurrence),
        };
    }

    /** @return list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable}> */
    private function parPas(\DateTimeImmutable $premierDebut, \DateTimeImmutable $finBorne, int $dureeSecondes, string $pas): array
    {
        $occurrences = [];
        $courant = $premierDebut;
        while ($courant <= $finBorne) {
            $occurrences[] = ['debut' => $courant, 'fin' => $courant->modify(sprintf('+%d seconds', $dureeSecondes))];
            $courant = $courant->modify($pas);
        }

        return $occurrences;
    }

    /** @return list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable}> */
    private function hebdomadaire(\DateTimeImmutable $premierDebut, \DateTimeImmutable $finBorne, int $dureeSecondes, Recurrence $recurrence): array
    {
        $jours = $recurrence->getJoursSemaine() !== [] ? $recurrence->getJoursSemaine() : [(int) $premierDebut->format('N')];
        $heure = $premierDebut->format('H:i:s');

        $occurrences = [];
        $curseur = $premierDebut->setTime(0, 0, 0);
        while ($curseur <= $finBorne) {
            $isoJour = (int) $curseur->format('N');
            if (\in_array($isoJour, $jours, true)) {
                $debutOccurrence = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $curseur->format('Y-m-d') . ' ' . $heure);
                if ($debutOccurrence !== false && $debutOccurrence >= $premierDebut) {
                    $occurrences[] = ['debut' => $debutOccurrence, 'fin' => $debutOccurrence->modify(sprintf('+%d seconds', $dureeSecondes))];
                }
            }
            $curseur = $curseur->modify('+1 day');
        }

        return $occurrences;
    }
}
