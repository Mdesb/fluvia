<?php

declare(strict_types=1);

namespace App\Marketing;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\Marketing` — posé le 20/10/2026 (recentrage sur la billetterie, PR 2).
 *
 * Le module était masqué par la spec mais n'avait pas de manifeste : sans lui, aucune de ses classes
 * n'appartenait à un module, donc la garde serveur, l'ordonnanceur et le menu ne pouvaient pas
 * l'éteindre. Il déclare ce qui existe, et rien de plus : ses permissions d'API, aucun
 * événement publié ni écouté au catalogue, aucune dépendance (les modules qui en consomment les
 * services PHP n'ont pas de manifeste à citer).
 *
 * ⚠ Éteint, la fidélité et le parrainage s'éteignent avec les campagnes : leurs routes sont dans
 * `App\Marketing`. L'écran Clients du cœur, qui lit le solde de fidélité, reçoit alors un 404.
 */
final class MarketingModule implements ModuleManifest
{
    public function id(): string
    {
        return 'marketing';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function capability(): ?string
    {
        return 'marketing';
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return [];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['campagne.gerer', 'campagne.lire', 'campagne.lire_journal', 'fidelite.gerer', 'fidelite.lire', 'fidelite.parametrer'];
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
