<?php

declare(strict_types=1);

namespace App\Stay;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\Stay` — le séjour (ACT-3, D16).
 *
 * **`eventsEmitted()` est vide, et ce n'est pas un oubli.** Le séjour a vocation à publier
 * `stay.opened`, `stay.charged`, `stay.closed` et `stay.settled` — mais aucun n'existe au
 * `CONTRACT/catalogue-evenements.md`, et RG-PLAT-06 refuse à la poussée tout événement hors
 * catalogue. Le catalogue appartient à `claude-A` ; déclarer ici des noms qu'il n'a pas arbitrés
 * inverserait la règle contract-first de D2 et ferait échouer la poussée de tout le monde. La
 * demande est posée dans `RAPPORTS/claude-F.md` ; les événements seront déclarés au lot suivant,
 * une fois catalogués — même prudence que `DmsModule`, qui ne reproduit que des lignes déjà actées.
 *
 * **`eventsConsumed()` est vide pour la même raison inverse.** Les faits à écouter existent bien au
 * catalogue (`sale.completed`, `access.recorded`, `booking.completed`), mais déclarer qu'on écoute
 * sans avoir de listener rendrait le graphe des réactions faux — or ce graphe sert justement à juger
 * l'impact d'un changement d'événement. On déclare ce qu'on fait, pas ce qu'on prévoit.
 *
 * **`dependencies()` est vide alors que le séjour s'appuie sur le client CRM.** Le registre refuse de
 * démarrer si une dépendance nomme un module inconnu (RG-PLAT-07), et `App\Crm` n'a pas de manifeste
 * à ce jour. Déclarer `crm` empêcherait la plateforme entière de démarrer pour documenter un lien que
 * le code exprime déjà par son typage.
 *
 * **`routes()` est vide par conception, pas par manque.** D13 : la note de séjour et l'encaissement
 * se font en modale au-dessus du contexte courant. Aucune des trois raisons d'ouvrir un écran — espace
 * de travail durable, contenu qui déborde, lien partageable — ne s'applique à un compte qu'on consulte
 * au comptoir pendant que le client attend.
 */
final class StayModule implements ModuleManifest
{
    public function id(): string
    {
        return 'stay';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    /**
     * Le séjour est **vendu et activable par établissement** : ce n'est pas un service transverse, il
     * n'a de sens que pour un exploitant qui héberge. Le code `stay` doit être enregistré au catalogue
     * de capacités (`App\Fonctionnalite`), qui est le périmètre de `claude-A` — sans quoi
     * `ModuleAccess::hasModule()` répondra toujours `false` et le module sera présent mais inaccessible.
     * Demande posée dans `RAPPORTS/claude-F.md`.
     */
    public function capability(): ?string
    {
        return 'stay';
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
            'stay.read',
            'stay.write',
            'stay.charge',
            'stay.settle',
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
