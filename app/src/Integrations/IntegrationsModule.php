<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module de connecteurs sortants — Slack, Teams, Discord, et l'automate maison.
 *
 * Module VENDU ET ACTIVABLE par établissement, donc porteur d'une capacité — contrairement à un
 * service transverse comme l'OCR, dont `capability()` est nulle.
 *
 * ⚠ **UN SEUL MODULE, PAS UN PAR OUTIL.** Le précédent est `SocialModule` : un module `social`, et
 * les réseaux (`mastodon`, `bluesky`) dans son schéma de réglages. Quatre capacités quasi
 * identiques se factureraient séparément pour un seul émetteur, et l'exploitant qui veut Slack
 * aujourd'hui et Teams demain se verrait vendre deux fois la même chose.
 *
 * ⚠ **`eventsEmitted()` EST VIDE, ET LE RESTERA.** La règle du dépôt est qu'un nom entre au
 * catalogue dans le même commit que son émetteur ET son abonné. Ce module ne produit aucun fait
 * métier : il CONSOMME ceux des autres. Déclarer un `integration.message_sent` sans preneur
 * ajouterait un nom que personne n'écoute — exactement ce que le cliquet « événements sans
 * preneur » surveille.
 *
 * ⚠ **`eventsConsumed()` EST VIDE AUSSI, ET C'EST DIFFÉRENT.** Ce module n'écoute pas une liste
 * fixe : il écoute ce que CHAQUE ÉTABLISSEMENT a coché sur ses destinations. Y recopier les 58
 * noms du catalogue donnerait une dépendance déclarée envers des domaines dont ce module ignore
 * tout, et il faudrait la maintenir à chaque événement ajouté ailleurs.
 */
final class IntegrationsModule implements ModuleManifest
{
    public function id(): string
    {
        return 'connecteurs';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function capability(): ?string
    {
        return 'connecteurs';
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return [];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        // Lire la liste des destinations et en déposer une sont deux gestes de sensibilité très
        // différente — le second dépose de quoi écrire dans un canal au nom de l'établissement.
        // Même séparation que `social.read_account` / `social.manage_account`, et pour la même
        // raison : on confie la consultation sans confier les clés.
        return ['connecteurs.lire', 'connecteurs.gerer_destination'];
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
        return ['/parametres?tab=connecteurs'];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [
            'kinds' => [
                'type' => 'enum_list',
                'values' => ['slack', 'teams', 'discord', 'generique'],
                'default' => [],
            ],
        ];
    }
}
