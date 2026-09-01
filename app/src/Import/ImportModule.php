<?php

declare(strict_types=1);

namespace App\Import;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\Import` (reprise initiale, plan-import-i1.md §7 point 7). `capability()`
 * renvoie `null` : la reprise de données n'est pas une capacité vendue par établissement au sens du
 * catalogue `App\Fonctionnalite` — c'est un outil d'onboarding, activé une fois par client à sa
 * signature (§0.5 de la spec). ⚠ Noms de permissions **proposés par analogie**, à arbitrer avec M8
 * avant figement (§3 du plan).
 *
 * `eventsEmitted()`/`eventsConsumed()` vides par ce lot (I1) — §7 point 8 du plan : émettre
 * `import.applied`/`import.reverted` est différé à I2, faute de consommateur identifié (D7).
 */
final class ImportModule implements ModuleManifest
{
    public function id(): string
    {
        return 'import';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function capability(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return [];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return [
            'import.read',
            'import.create',
            'import.apply',
            'import.revert',
        ];
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
        return ['data_import'];
    }

    /** @return list<string> */
    public function routes(): array
    {
        return ['/imports'];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [];
    }
}
