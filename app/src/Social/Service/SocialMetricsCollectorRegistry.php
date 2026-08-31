<?php

declare(strict_types=1);

namespace App\Social\Service;

use App\Social\Enum\SocialNetwork;
use App\Social\Port\SocialMetricsCollector;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Résout le collecteur de statistiques d'un réseau (SOC-3) — même patron que
 * `SocialPublisherRegistry`.
 *
 * `supports()` existe pour que l'appelant puisse **ne pas demander** plutôt que d'attraper une
 * exception : un réseau qui sait publier mais pas encore mesurer est un état légitime pendant qu'on
 * construit, pas une panne.
 */
final class SocialMetricsCollectorRegistry
{
    /** @var array<string, SocialMetricsCollector>|null */
    private ?array $byNetwork = null;

    /**
     * @param iterable<SocialMetricsCollector> $collectors
     */
    public function __construct(
        #[AutowireIterator('social.metrics_collector')]
        private readonly iterable $collectors,
    ) {
    }

    public function for(SocialNetwork $network): SocialMetricsCollector
    {
        return $this->map()[$network->value]
            ?? throw new \InvalidArgumentException(sprintf('Aucun collecteur de statistiques pour « %s ».', $network->value));
    }

    public function supports(SocialNetwork $network): bool
    {
        return isset($this->map()[$network->value]);
    }

    /** @return array<string, SocialMetricsCollector> */
    private function map(): array
    {
        if ($this->byNetwork !== null) {
            return $this->byNetwork;
        }

        $map = [];
        foreach ($this->collectors as $collector) {
            foreach (SocialNetwork::cases() as $network) {
                if ($collector->supports($network)) {
                    $map[$network->value] = $collector;
                }
            }
        }

        return $this->byNetwork = $map;
    }
}
