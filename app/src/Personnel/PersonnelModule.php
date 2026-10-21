<?php

declare(strict_types=1);

namespace App\Personnel;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\Personnel` — posé le 20/10/2026 (recentrage sur la billetterie, PR 2).
 *
 * Le module n'avait pas de manifeste : aucune de ses classes n'appartenait à un module. Il déclare
 * ce qui existe, et rien de plus : ses permissions d'API, aucun
 * événement publié ni écouté au catalogue, aucune dépendance (les modules qui en consomment les
 * services PHP n'ont pas de manifeste à citer).
 *
 * ⚠ PERSONNEL EST DU CŒUR (décision de Maxime du 20/10, à la relecture de la PR 2) : sa capacité
 * existe mais ne s'éteint pas (422), la garde ne le filtre pas, et ses trois tâches planifiées
 * tournent toujours — la sortie d'un salarié révoque ses badges, la sauter le laisserait entrer.
 */
final class PersonnelModule implements ModuleManifest
{
    public function id(): string
    {
        return 'personnel';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function capability(): ?string
    {
        return 'personnel';
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return [];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['personnel.declarer_absence_soi', 'personnel.gerer', 'personnel.gerer_badge', 'personnel.gerer_employe', 'personnel.gerer_planning', 'personnel.gerer_qualification', 'personnel.lire', 'personnel.lire_soi', 'personnel.valider_absence'];
    }

    /** @return list<string> */
    public function eventsEmitted(): array
    {
        return [];
    }

    /** @return list<string> */
    public function eventsConsumed(): array
    {
        return [];
    }

    /** @return list<string> */
    public function features(): array
    {
        return [];
    }

    /** @return list<string> */
    public function routes(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [];
    }
}
