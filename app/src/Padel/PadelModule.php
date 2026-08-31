<?php

declare(strict_types=1);

namespace App\Padel;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\Padel` — verticale métier (D15).
 *
 * **`features()` est vide, et c'est un écart relevé, pas un oubli.** Le padel loue du matériel
 * (`LocationMateriel`, `CautionMateriel`) mais le préset historique ne lui accorde jamais
 * `location_materiel` — seule la patinoire l'obtient. Déclarer ici une feature que le catalogue
 * n'accorde pas au padel la rendrait toujours fausse. Signalé à `claude-A`.
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
final class PadelModule implements ModuleManifest
{
    public function id(): string
    {
        return 'padel';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    /**
     * Vendue et activable par établissement. Le code `padel` existe déjà au catalogue de capacités
     * (`App\Fonctionnalite\Enum\CapaciteCode`), donc `ModuleAccess::hasModule()` peut répondre vrai —
     * contrairement à un code inventé, qui rendrait le module présent et définitivement inaccessible.
     */
    public function capability(): ?string
    {
        return 'padel';
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
            'padel.lire',
            'padel.lire_soi',
            'padel.parametrer',
            'padel.gerer_terrain',
            'padel.reserver',
            'padel.reserver_soi',
            'padel.partie_rejoindre_soi',
            'padel.acces_forcer',
            'padel.materiel',
            'padel.materiel_gerer',
            'padel.niveau_declarer_soi',
            'padel.niveau_valider',
            'padel.tournoi_gerer',
            'padel.tournoi_inscrire_soi',
            'padel.configurer_eclairage',
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
     * « Partie » doit pouvoir le changer, et ne jamais le reperdre à une montée de
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
                'resource' => 'Terrain',
                'resource_unit' => 'Terrain',
                'slot' => 'Réservation de terrain',
                'booking' => 'Partie',
                'participant' => 'Joueur',
                'staff' => 'Juge-arbitre',
                'group' => 'Poule',
                'entry' => 'Accès terrain',
                'rental' => 'Location de matériel',
                'deposit' => 'Caution matériel',
                'multi_entry_card' => 'Carte de parties',
            ],
        ];
    }
}
