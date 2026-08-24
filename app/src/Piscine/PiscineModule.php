<?php

declare(strict_types=1);

namespace App\Piscine;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\Piscine` — verticale métier (D15).
 *
 * **`features()` ne porte que `poss`.** Le préset historique accordait aussi `casiers` et
 * `encadrants` — mais ces deux codes sont accordés **à la fois** à la piscine et à la patinoire, et
 * `ModuleAccess::moduleDeclarant()` retourne le premier manifeste qui déclare une feature. Deux
 * modules déclarant le même code rendraient la résolution ambiguë : la location de patins serait
 * gardée par l'activation de la piscine. Signalé à `claude-A` dans `RAPPORTS/claude-I.md`.
 * *
 * **`dependencies()` est vide alors que la verticale s'appuie sur l'accès et la réservation.** Le
 * registre refuse de démarrer si une dépendance nomme un module inconnu (RG-PLAT-07), et ni
 * `App\Acces` ni `App\Reservation` n'ont de manifeste à ce jour. Déclarer le lien empêcherait la
 * plateforme entière de démarrer pour documenter ce que le typage exprime déjà — même prudence que
 * `StayModule`.
 *
 * **`eventsEmitted()` et `eventsConsumed()` sont vides.** La verticale ne publie aucun événement de
 * domaine, et ce qu'elle écoute ne figure pas au `CONTRACT/catalogue-evenements.md` : RG-PLAT-06
 * refuse à la poussée tout événement hors catalogue. On déclare ce qu'on fait, pas ce qu'on prévoit.
 *
 * **`routes()` est vide par conception (D13)** : le paramétrage d'une verticale se fait en modale
 * au-dessus du contexte courant, pas dans un écran dédié.
 */
final class PiscineModule implements ModuleManifest
{
    public function id(): string
    {
        return 'piscine';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    /**
     * Vendue et activable par établissement. Le code `piscine` existe déjà au catalogue de capacités
     * (`App\Fonctionnalite\Enum\CapaciteCode`), donc `ModuleAccess::hasModule()` peut répondre vrai —
     * contrairement à un code inventé, qui rendrait le module présent et définitivement inaccessible.
     */
    public function capability(): ?string
    {
        return 'piscine';
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
            'piscine.lire',
            'piscine.gerer',
            'piscine.configurer',
            'piscine.casier',
            'piscine.gerer_casier',
            'piscine.forcer_casier',
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
        return ['poss'];
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
