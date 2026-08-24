<?php

declare(strict_types=1);

namespace App\Social;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module de publication sociale (D14) — enregistré automatiquement par le tag porté par
 * l'interface, aucune ligne de configuration à ajouter.
 *
 * Contrairement à OCR (service transverse, `capability()` nulle), la publication sociale est un module
 * vendu et activable par établissement : il a donc une capacité. Un seul module pour deux usages —
 * celui des clients et celui de l'éditeur, qui est un établissement de la plateforme comme un autre
 * (D12). Aucun second développement.
 *
 * `eventsEmitted()` est délibérément vide à ce stade. Le cliquet « événements sans preneur » du dépôt
 * est gelé, et la règle qui en découle est qu'un nom entre au catalogue dans le même commit que son
 * émetteur et son abonné. `social.post_published` viendra donc avec SOC-3 (collecte de statistiques),
 * qui en est le premier consommateur réel — pas avant, faute de quoi on déclarerait un fait que
 * personne n'écoute.
 */
final class SocialModule implements ModuleManifest
{
    public function id(): string
    {
        return 'social';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function capability(): ?string
    {
        return 'social';
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return [];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        // Lire la liste des comptes connectés et en connecter un sont deux gestes de sensibilité très
        // différente : le second dépose de quoi publier au nom de l'établissement. Une seule
        // permission pour les deux donnerait le droit de connecter à qui n'a besoin que de consulter.
        // Rédiger et publier est encore un cran au-dessus de gérer les comptes : c'est le geste qui
        // parle en public au nom de l'établissement. Une permission distincte permet de confier la
        // communication à quelqu'un sans lui confier les jetons — et l'inverse.
        return ['social.read_account', 'social.manage_account', 'social.read_post', 'social.publish'];
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
        // Aucun écran déclaré tant que SOC-2 n'a pas de quoi publier : la connexion d'un compte est
        // une modale au-dessus du paramétrage (D13), pas une navigation propre. Déclarer une route
        // vers un écran vide serait une promesse d'interface que le module ne tient pas encore.
        return [];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [
            'networks' => [
                'type' => 'enum_list',
                'values' => ['mastodon', 'bluesky'],
                'default' => [],
            ],
        ];
    }
}
