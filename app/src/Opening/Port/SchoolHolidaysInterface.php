<?php

declare(strict_types=1);

namespace App\Opening\Port;

use App\Opening\Enum\SchoolZone;

/**
 * LES VACANCES SCOLAIRES — un port, parce qu'elles viennent de dehors.
 *
 * ── POURQUOI UN PORT ALORS QUE LES FÉRIÉS SONT CALCULÉS ─────────────────────────────────────────
 *
 * Les jours fériés se calculent : huit dates fixes et trois dérivées de Pâques, pour toujours. Les
 * vacances scolaires, non — elles sont fixées par arrêté, elles changent chaque année, et aucune
 * formule ne les rend. Il faut donc aller les chercher, et tout ce qui vient de dehors passe par un
 * port : le domaine doit se tester entièrement sans le tiers.
 *
 * ── « RIEN TROUVÉ » ET « PAS PU CHERCHER » NE SE DISENT PAS PAREIL ──────────────────────────────
 *
 * C'est la raison d'être du champ `available`. Une liste vide parce que la période ne contient pas
 * de vacances, et une liste vide parce que le ministère n'a pas répondu, appellent deux gestes
 * différents à l'écran : dans le premier cas il n'y a rien à dire, dans le second il faut le dire.
 * Un port qui rendrait seulement une liste rendrait ces deux situations indiscernables — et l'écran
 * afficherait « aucune vacance » un jour de panne, ce qui est un mensonge tranquille.
 */
interface SchoolHolidaysInterface
{
    /**
     * Les périodes de vacances de cette zone qui recoupent l'intervalle.
     *
     * @return array{available: bool, periods: list<array{label: string, start: string, end: string}>, reason?: string}
     */
    public function periods(SchoolZone $zone, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
