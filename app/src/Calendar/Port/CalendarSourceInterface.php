<?php

declare(strict_types=1);

namespace App\Calendar\Port;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * CE QU'UN MODULE PUBLIE DANS L'AGENDA — et pourquoi l'agenda ne va pas le chercher lui-même.
 *
 * ── LE DÉFAUT QUE CE PORT CORRIGE ───────────────────────────────────────────────────────────────
 *
 * La première version de `CalendarAggregator` lisait `reservation_creneau` et
 * `personnel_creneau_travail` en SQL BRUT, depuis le module Calendar. Ça marchait, et c'était une
 * dette à trois titres :
 *
 *   1. **D2 l'interdit** — aucun appel de module à module, et lire la table d'un autre est pire
 *      qu'appeler son service : c'est en dépendre sans que rien ne le déclare ;
 *   2. le renommage en anglais de mon module a traduit `c.establishment_id` sur une table qui
 *      s'appelle `etablissement_id`. **Du SQL en chaîne de caractères échappe à tous les outils** —
 *      au lint, au garde-fou de nommage, à l'analyse statique. Rien ne l'aurait dit avant
 *      l'exécution ;
 *   3. le jour où Réservation renomme une colonne, c'est l'agenda qui casse, sans que personne
 *      chez Réservation ait de raison de le savoir.
 *
 * ── CHACUN PUBLIE CE QU'IL A ────────────────────────────────────────────────────────────────────
 *
 * Chaque module implémente ce port et le tague ; l'agenda ne connaît que l'interface. Un module qui
 * n'a rien à publier ne fournit pas d'implémentation, et l'agenda ne s'en aperçoit pas — c'est ce
 * qui rend l'ajout d'une verticale gratuit pour lui.
 *
 * ── LA PORTÉE EST UN ARGUMENT, PAS UN FILTRE APPLIQUÉ APRÈS ─────────────────────────────────────
 *
 * `site` et `mine` ne sont pas deux vues de la même liste : « que se passe-t-il ici ? » et « que
 * dois-je faire, moi ? » sont deux questions. Une source de créneaux de travail n'a rien à dire au
 * site ; une source de créneaux de réservation n'a rien à dire à « moi ». Filtrer après coup
 * obligerait chaque source à rendre ce qu'on va jeter, et à traverser un cloisonnement pour rien.
 */
#[AutoconfigureTag('calendar.source')]
interface CalendarSourceInterface
{
    /**
     * Les occurrences de ce module qui recoupent l'intervalle.
     *
     * @param string $scope `site` ou `mine`
     *
     * @return list<array{id: string, source: string, title: string, start: string, end: string, allDay: bool, type: string, scope: string, detail: string|null}>
     */
    public function occurrences(
        Etablissement $etablissement,
        Utilisateur $utilisateur,
        \DateTimeImmutable $du,
        \DateTimeImmutable $au,
        string $scope,
    ): array;
}
