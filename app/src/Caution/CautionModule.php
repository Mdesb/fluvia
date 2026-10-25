<?php

declare(strict_types=1);

namespace App\Caution;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\Caution` — posé le 20/10/2026 (recentrage sur la billetterie, PR 2).
 *
 * Le module était masqué par la spec mais n'avait pas de manifeste : sans lui, aucune de ses classes
 * n'appartenait à un module, donc la garde serveur, l'ordonnanceur et le menu ne pouvaient pas
 * l'éteindre. Il déclare ce qui existe, et rien de plus : ses permissions d'API, aucun
 * événement publié ni écouté au catalogue, aucune dépendance (les modules qui en consomment les
 * services PHP n'ont pas de manifeste à citer).
 *
 * ⚠ Éteint, seules ses routes et ses traitements de fond s'arrêtent. Les verticales qui créent une
 * caution en PHP (piscine, padel, patinoire) continuent de le faire.
 */
final class CautionModule implements ModuleManifest
{
    public function id(): string
    {
        return 'caution';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function capability(): ?string
    {
        return 'caution';
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return [];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['caution.lire', 'caution.parametrer', 'caution.piloter'];
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
