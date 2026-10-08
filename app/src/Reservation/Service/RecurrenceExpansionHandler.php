<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Recurrence;
use App\Reservation\Enum\MotifRecurrence;

/**
 * Génère les occurrences d'une récurrence (RG-M5-07) : hebdomadaire (jours de semaine donnés, ou le
 * jour du premier créneau à défaut), quotidien, mensuel (même jour du mois). Chaque occurrence
 * conserve la durée du premier créneau.
 *
 * ⚠ LE CALENDRIER EST CELUI DE L'ÉTABLISSEMENT, LE STOCKAGE EST EN UTC. Les jours et l'heure se
 * comptent dans `Etablissement::$fuseauHoraire`, puis chaque occurrence est convertie en UTC pour
 * elle-même. Posées à la même heure UTC que la première, les séances glissaient d'une heure au
 * changement d'heure : un cours de 18:00 posé en septembre tombait à 17:00 à Paris après le 25/10.
 * Heure dupliquée et heure manquante : voir `Etablissement::instantLocal()`.
 */
final class RecurrenceExpansionHandler
{
    /**
     * @return list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable}> instants UTC
     */
    public function genererOccurrences(\DateTimeImmutable $premierDebut, \DateTimeImmutable $premierFin, Recurrence $recurrence): array
    {
        $etablissement = $recurrence->getEtablissement();
        $local = $premierDebut->setTimezone(new \DateTimeZone($etablissement?->getFuseauHoraire() ?? 'Europe/Paris'));
        $dureeSecondes = $premierFin->getTimestamp() - $premierDebut->getTimestamp();
        $dernierJour = $recurrence->getFinRecurrence()->format('Y-m-d');
        $jours = $recurrence->getMotif() !== MotifRecurrence::Hebdomadaire ? null
            : ($recurrence->getJoursSemaine() !== [] ? $recurrence->getJoursSemaine() : [(int) $local->format('N')]);
        $pas = $recurrence->getMotif() === MotifRecurrence::Mensuel ? '+1 month' : '+1 day';

        $occurrences = [];
        // Le curseur est une date civile, sans heure qui puisse changer : un pas d'un jour ou d'un mois
        // y reste un jour ou un mois.
        for ($jour = new \DateTimeImmutable($local->format('Y-m-d')); $jour->format('Y-m-d') <= $dernierJour; $jour = $jour->modify($pas)) {
            if ($jours !== null && !\in_array((int) $jour->format('N'), $jours, true)) {
                continue;
            }
            $debut = $jour->format('Y-m-d') === $local->format('Y-m-d')
                ? $premierDebut->setTimezone(new \DateTimeZone('UTC'))
                : Etablissement::instantLocal($etablissement, $jour->format('Y-m-d ') . $local->format('H:i:s'));
            $occurrences[] = ['debut' => $debut, 'fin' => $debut->modify(sprintf('+%d seconds', $dureeSecondes))];
        }

        return $occurrences;
    }
}
