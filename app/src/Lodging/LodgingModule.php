<?php

declare(strict_types=1);

namespace App\Lodging;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\Lodging` — l'hébergement (ACT-2, D15/D16).
 *
 * **Une couche mince, pas un module parallèle.** `claude-G` a livré la réservation par type et
 * l'affectation différée : on réserve une chambre double, l'instance est désignée plus tard, et deux
 * affectations qui se chevauchent sont refusées. `App\Lodging` n'en refait rien — il ajoute la
 * sémantique que la réservation ne porte pas : la **nuitée**, le tarif par nuit, le calendrier
 * d'occupation.
 *
 * **`dependencies()` est vide alors que l'hébergement s'appuie sur la réservation.** `App\Reservation`
 * n'a pas de manifeste à ce jour ; le registre refuse de démarrer si une dépendance nomme un module
 * inconnu (RG-PLAT-07). Déclarer `reservation` empêcherait donc la plateforme entière de démarrer pour
 * documenter un lien que le code exprimera par son typage. Signalé à l'intégrateur.
 *
 * **`eventsEmitted()` et `eventsConsumed()` sont vides**, pour les mêmes raisons que `App\Stay` :
 * RG-PLAT-06 refuse tout événement absent du `CONTRACT/catalogue-evenements.md`, et on ne déclare pas
 * écouter ce qu'on n'écoute pas encore.
 */
final class LodgingModule implements ModuleManifest
{
    public function id(): string
    {
        return 'lodging';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    /** Vendu et activable par établissement : un camping l'active, un musée non. */
    public function capability(): ?string
    {
        return 'lodging';
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
            'lodging.read',
            'lodging.write',
            'lodging.manage_rates',
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
