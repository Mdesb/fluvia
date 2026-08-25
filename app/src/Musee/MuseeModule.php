<?php

declare(strict_types=1);

namespace App\Musee;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\Musee` — verticale métier (D15).
 *
 * **`features()` est vide** : le préset musée n'accorde que des capacités transverses
 * (`controle_acces`, `reservation`, `boutique_en_ligne`), aucune qui lui soit propre. C'est
 * cohérent avec l'inventaire de `specs/verticales/composition.md` : le musée est la verticale la plus
 * proche du noyau, son seul vrai module de code est la distribution OTA.
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
final class MuseeModule implements ModuleManifest
{
    public function id(): string
    {
        return 'musee';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    /**
     * Vendue et activable par établissement. Le code `musee` existe déjà au catalogue de capacités
     * (`App\Fonctionnalite\Enum\CapaciteCode`), donc `ModuleAccess::hasModule()` peut répondre vrai —
     * contrairement à un code inventé, qui rendrait le module présent et définitivement inaccessible.
     */
    public function capability(): ?string
    {
        return 'musee';
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
            'musee.lire',
            'musee.gerer',
            'musee.configurer',
            'musee.gerer_visite',
            'musee.gerer_dossier_groupe',
            'musee.gerer_pass',
            'musee.gerer_ota',
            'musee.superviser_salle',
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

    /**
     * **Les mots de ce métier (D15).** Un « créneau » est un *rendez-vous* chez le coiffeur et une
     * *réservation de terrain* au padel : même concept, mots différents. Les cles sont celles du
     * catalogue commun — anglaises, donc techniques (D5) ; les valeurs sont le libellé par défaut, en
     * attendant la couche i18n, qui n'existe pas encore dans ce dépôt.
     *
     * **Pourquoi ici plutôt que dans un document.** `settingsSchema()` est déjà le schéma de
     * configuration par tenant, et `SmartFlowModule` s'en sert de la même façon. Une surcharge de
     * vocabulaire *est* de la configuration par établissement : l'exploitant qui préfère « usager » à
     * « Reservation » doit pouvoir le changer, et ne jamais le reperdre à une montée de
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
                'resource' => 'Salle',
                'resource_unit' => 'Salle',
                'slot' => 'Créneau de visite',
                'booking' => 'Réservation',
                'participant' => 'Visiteur',
                'staff' => 'Guide',
                'group' => 'Groupe scolaire',
                'entry' => 'Billet',
                'rental' => 'Audioguide',
                'capacity' => 'Jauge de salle',
                'multi_entry_card' => 'Pass annuel',
            ],
        ];
    }
}
