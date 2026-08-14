<?php

declare(strict_types=1);

namespace App\Offre\Service;

use App\Offre\Entity\Formule;
use App\Offre\Entity\ServiceInclus;
use App\Offre\Enum\PeriodeQuota;

/**
 * Décompte des quotas de services inclus en semaine calendaire (RG-M1-12 / CA-7) : remise à zéro
 * le lundi, fenêtre lundi→dimanche, SANS report des non-utilisés. Fournit aussi une simulation
 * « semaine type » des droits avant publication.
 */
final class SimulateurQuota
{
    /**
     * Renvoie [debut(lundi 00:00), fin(dimanche 23:59:59)] de la semaine calendaire contenant $date.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function fenetreSemaine(\DateTimeImmutable $date): array
    {
        $jour = (int) $date->format('N'); // 1 = lundi ... 7 = dimanche
        $lundi = $date->modify('-' . ($jour - 1) . ' days')->setTime(0, 0, 0);
        $dimanche = $lundi->modify('+6 days')->setTime(23, 59, 59);

        return [$lundi, $dimanche];
    }

    /**
     * Quota restant sur la semaine de $date, à partir des consommations fournies. Sans report :
     * seules les consommations DANS la fenêtre de la semaine courante sont décomptées.
     *
     * @param list<\DateTimeImmutable> $consommations
     */
    public function quotaRestant(ServiceInclus $service, \DateTimeImmutable $date, array $consommations): int
    {
        if ($service->getPeriode() !== PeriodeQuota::SemaineCalendaire) {
            return $service->getQuota();
        }

        [$debut, $fin] = $this->fenetreSemaine($date);

        $utilisees = 0;
        foreach ($consommations as $consommation) {
            if ($consommation >= $debut && $consommation <= $fin) {
                ++$utilisees;
            }
        }

        return max(0, $service->getQuota() - $utilisees);
    }

    /**
     * Simulation « semaine type » : droits d'une semaine pour chaque service inclus de la formule
     * (aucun report d'une semaine sur l'autre) — CA-7.
     *
     * @return list<array{activiteRef: string, quotaSemaine: int, periode: string}>
     */
    public function simulerSemaineType(Formule $formule): array
    {
        $droits = [];
        foreach ($formule->getServicesInclus() as $service) {
            $droits[] = [
                'activiteRef' => (string) $service->getActiviteRef(),
                'quotaSemaine' => $service->getQuota(),
                'periode' => $service->getPeriode()->value,
            ];
        }

        return $droits;
    }
}
