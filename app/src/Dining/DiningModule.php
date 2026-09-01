<?php

declare(strict_types=1);

namespace App\Dining;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\Dining` — la restauration (ACT-4, D15/D16).
 *
 * **Une couche mince, comme l'hébergement.** `claude-G` a livré tout le versant réservation : une
 * table de huit consomme huit couverts sur les soixante d'un service (`Reservation.unitesConsommees`),
 * et `JaugeCreneauGuard` porte la capacité à deux niveaux. `App\Dining` n'en refait rien — il ajoute
 * ce qui se passe **à table** : le service, l'addition, l'envoi en cuisine.
 *
 * **`dependencies()` est vide** alors que la restauration s'appuie sur la réservation : `App\Reservation`
 * n'a toujours pas de manifeste, et le registre refuse de démarrer si une dépendance nomme un module
 * inconnu (RG-PLAT-07). Troisième module de mon périmètre dans ce cas, après `Stay` (`crm`) et
 * `Lodging` (`reservation`) — signalé à l'intégrateur.
 *
 * **`eventsEmitted()` et `eventsConsumed()` sont vides** : RG-PLAT-06 refuse tout événement absent du
 * catalogue, et on ne déclare pas écouter ce qu'on n'écoute pas encore.
 */
final class DiningModule implements ModuleManifest
{
    public function id(): string
    {
        return 'dining';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    /** Vendu et activable par établissement : un restaurant l'active, une patinoire sans bar non. */
    public function capability(): ?string
    {
        return 'dining';
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
            'dining.read',
            'dining.write',
            'dining.fire',
            'dining.void',
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
