<?php

declare(strict_types=1);

namespace App\Opening\Enum;

/**
 * Zone de vacances scolaires (France métropolitaine) : A, B ou C.
 *
 * ── POURQUOI C'EST UN RÉGLAGE ET NON UNE DÉDUCTION ──────────────────────────────────────────────
 *
 * La zone se déduit du département, et le département de l'adresse. On pourrait donc la calculer.
 * On ne le fait pas — ou plutôt, on la PROPOSE et on laisse changer, pour une raison de métier :
 * une salle de sport en limite d'académie remplit ses créneaux avec la clientèle d'à côté, et c'est
 * le calendrier de SES clients qui l'intéresse, pas celui de son propre code postal.
 *
 * ⚠ Une zone n'est pas un horaire. Elle ne ferme rien et n'ouvre rien : elle sert à AFFICHER les
 * périodes de vacances en fond de calendrier, pour qu'un exploitant voie tout de suite pourquoi sa
 * fréquentation change. Les confondre avec des fermetures donnerait un contrôle d'accès qui refuse
 * du monde pendant les vacances de la Toussaint.
 */
enum SchoolZone: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';

    /** Le libellé attendu par le jeu de données du ministère (`zones` = « Zone A »). */
    public function officialLabel(): string
    {
        return 'Zone ' . $this->value;
    }
}
