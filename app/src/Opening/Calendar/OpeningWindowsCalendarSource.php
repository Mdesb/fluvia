<?php

declare(strict_types=1);

namespace App\Opening\Calendar;

use App\Calendar\Port\CalendarSourceInterface;
use App\Opening\Service\OpeningCalendar;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;

/**
 * CE QU'OUVERTURE PUBLIE DANS L'AGENDA : les heures pendant lesquelles la porte est ouverte.
 *
 * ── UN ARRIÈRE-PLAN, PAS UN RENDEZ-VOUS ─────────────────────────────────────────────────────────
 *
 * Une plage d'ouverture n'a pas lieu : elle est le cadre dans lequel le reste a lieu. L'écran la
 * dessine derrière les autres blocs — et c'est ce qui rend visible exactement ce qu'on cherchait :
 * le créneau posé en dehors des heures d'ouverture, l'erreur que rien ne relevait avant ce module.
 *
 * ── LE CALCUL RESTE CHEZ `OpeningCalendar` ──────────────────────────────────────────────────────
 *
 * Cette source ne recalcule rien : elle appelle le même service que le contrôle d'accès. S'ils
 * calculaient chacun de leur côté, l'écran finirait par montrer une heure d'ouverture pendant
 * laquelle la porte refuse — le pire des deux mondes, puisque l'exploitant aurait sous les yeux la
 * preuve écrite qu'il devrait pouvoir entrer.
 */
final readonly class OpeningWindowsCalendarSource implements CalendarSourceInterface
{
    public function __construct(private OpeningCalendar $calendrier)
    {
    }

    /**
     * @return list<array{id: string, source: string, title: string, start: string, end: string, allDay: bool, type: string, scope: string, detail: string|null}>
     */
    public function occurrences(
        Etablissement $etablissement,
        Utilisateur $utilisateur,
        \DateTimeImmutable $du,
        \DateTimeImmutable $au,
        string $scope,
    ): array {
        // L'ouverture cadre LE SITE. Dans « Moi », elle n'apprendrait rien : ce qu'on y cherche,
        // c'est ce qu'on a à faire, pas quand le bâtiment est accessible.
        if ($scope !== 'site') {
            return [];
        }

        $lignes = [];
        foreach ($this->calendrier->fenetres($etablissement, $du, $au) as $i => $fenetre) {
            $lignes[] = [
                'id' => 'opening-' . $fenetre['day'] . '-' . $i,
                'source' => 'opening',
                'title' => $fenetre['label'] ?? 'Ouvert',
                'start' => $fenetre['start']->format(\DateTimeInterface::ATOM),
                'end' => $fenetre['end']->format(\DateTimeInterface::ATOM),
                'allDay' => false,
                'type' => 'opening',
                'scope' => 'site',
                'detail' => null,
            ];
        }

        return $lignes;
    }
}
