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

    /**
     * **Les mots de ce métier (D15).** Un « créneau » est un *rendez-vous* chez le coiffeur et une
     * *réservation de terrain* au padel : même concept, mots différents. Les cles sont celles du
     * catalogue commun — anglaises, donc techniques (D5) ; les valeurs sont le libellé par défaut, en
     * attendant la couche i18n, qui n'existe pas encore dans ce dépôt.
     *
     * **Pourquoi ici plutôt que dans un document.** `settingsSchema()` est déjà le schéma de
     * configuration par tenant, et `SmartFlowModule` s'en sert de la même façon. Une surcharge de
     * vocabulaire *est* de la configuration par établissement : l'exploitant qui préfère « usager » à
     * « Baigneur » doit pouvoir le changer, et ne jamais le reperdre à une montée de
     * version (D15).
     *
     * Ce que cette verticale ne surcharge pas ne figure pas ici : le défaut commun s'applique. Un
     * tiret au catalogue vaut décision, pas trou à combler.
     *
     * @return array<string, mixed>
     */
    public function settingsSchema(): array
    {
        return [
            'vocabulary' => [
                'resource' => 'Bassin',
                'resource_unit' => 'Ligne d\'eau',
                'slot' => 'Créneau public',
                'participant' => 'Baigneur',
                'staff' => 'Maître-nageur',
                'capacity' => 'Jauge POSS',
                'deposit' => 'Caution casier',
                'multi_entry_card' => 'Carte d\'entrees',
            ],
        ];
    }
}
