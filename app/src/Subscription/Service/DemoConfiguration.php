<?php

declare(strict_types=1);

namespace App\Subscription\Service;

use App\Organisation\Entity\Etablissement;
use App\Subscription\Port\ConfigurationSnapshotProvider;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Prend l'empreinte du paramétrage de démo, et la rejoue sur l'établissement livré (RG-ED-08, CA-7).
 *
 * **Un export, pas une copie de base** (D11). Le document produit ici est autonome : il est rangé sur
 * l'abonnement au moment de la souscription et rejoué après le paiement, à un moment où
 * l'établissement de démo peut avoir été détruit. C'est ce qui permet à la démo de rester le bac à
 * sable jetable qu'elle doit être, au lieu de devenir un établissement fantôme qu'il faudrait faire
 * expirer et purger.
 *
 * **Le rejeu n'allume jamais un module non souscrit.** C'est la règle qui compte ici. Le prospect a
 * pu essayer en démo des modules qu'il n'a finalement pas achetés ; rejouer leur configuration
 * activerait un service non facturé, et le client le découvrirait le jour où on le lui retire. On
 * confronte donc chaque instantané aux capacités réellement souscrites, et on écarte le reste en
 * silence — l'écart est normal, ce n'est pas une erreur.
 *
 * **Un module disparu ne fait pas échouer la souscription.** Un document peut contenir l'instantané
 * d'un module désinstallé depuis, ou renommé. Le rejeu l'ignore : refuser de provisionner un client
 * qui a payé, parce qu'un module qu'il n'a plus n'existe plus, serait un mauvais échange.
 */
final class DemoConfiguration
{
    /**
     * Version du format d'enveloppe.
     *
     * Elle porte la structure du document — `version`, `modules` — et non le contenu des instantanés,
     * dont chaque module reste responsable. Un document d'une version inconnue est ignoré plutôt que
     * réinterprété au jugé.
     */
    public const FORMAT_VERSION = 1;

    /**
     * @param iterable<ConfigurationSnapshotProvider> $providers
     */
    public function __construct(
        #[AutowireIterator('subscription.configuration_snapshot')]
        private readonly iterable $providers,
    ) {
    }

    /**
     * Le paramétrage de l'établissement de démo, sous forme de document rejouable.
     *
     * @return array{version: int, modules: array<string, array<string, mixed>>}
     */
    public function capture(Etablissement $demo): array
    {
        $modules = [];

        foreach ($this->providers as $provider) {
            $snapshot = $provider->capture($demo);

            // Un module sans configuration n'encombre pas le document : une clé vide se lit comme
            // « configuré à vide », ce qui n'est pas la même chose que « pas configuré ».
            if ([] !== $snapshot) {
                $modules[$provider->capability()] = $snapshot;
            }
        }

        return ['version' => self::FORMAT_VERSION, 'modules' => $modules];
    }

    /**
     * Rejoue le document sur l'établissement livré, dans la limite de ce qui a été acheté.
     *
     * @param array<string, mixed>|null $document      tel que rangé sur l'abonnement
     * @param list<string>              $subscribed    capacités réellement souscrites
     *
     * @return list<string> les capacités effectivement rejouées, pour la trace
     */
    public function replay(Etablissement $target, ?array $document, array $subscribed): array
    {
        if (null === $document || self::FORMAT_VERSION !== ($document['version'] ?? null)) {
            return [];
        }

        $modules = $document['modules'] ?? [];
        if (!\is_array($modules)) {
            return [];
        }

        $replayed = [];

        foreach ($this->providers as $provider) {
            $capability = $provider->capability();

            if (!\in_array($capability, $subscribed, true)) {
                continue;
            }

            $snapshot = $modules[$capability] ?? null;
            if (!\is_array($snapshot) || [] === $snapshot) {
                continue;
            }

            $provider->replay($target, $snapshot);
            $replayed[] = $capability;
        }

        return $replayed;
    }
}
