<?php

declare(strict_types=1);

namespace App\Social\Service;

use App\Social\Enum\SocialNetwork;
use App\Social\Port\SocialPublisher;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Résout l'adaptateur de publication d'un réseau (SOC-2) — itérateur taggé `social.publisher`, aucun
 * `switch`. Même patron que `App\Ocr\Service\DocumentExtractorRegistry` et
 * `App\Reporting\Service\ResolveurGenerateurExport`.
 *
 * Un réseau sans adaptateur lève plutôt que de rendre `null` : un compte connectable dont personne ne
 * sait publier serait une promesse que le module ne tient pas, et le silence la ferait découvrir à la
 * première tentative réelle.
 */
final class SocialPublisherRegistry
{
    /** @var array<string, SocialPublisher>|null */
    private ?array $byNetwork = null;

    /**
     * @param iterable<SocialPublisher> $publishers
     */
    public function __construct(
        #[AutowireIterator('social.publisher')]
        private readonly iterable $publishers,
    ) {
    }

    public function for(SocialNetwork $network): SocialPublisher
    {
        return $this->map()[$network->value]
            ?? throw new \InvalidArgumentException(sprintf('Aucun adaptateur de publication enregistré pour « %s ».', $network->value));
    }

    public function supports(SocialNetwork $network): bool
    {
        return isset($this->map()[$network->value]);
    }

    /** @return array<string, SocialPublisher> */
    private function map(): array
    {
        if ($this->byNetwork !== null) {
            return $this->byNetwork;
        }

        $map = [];
        foreach ($this->publishers as $publisher) {
            foreach (SocialNetwork::cases() as $network) {
                if ($publisher->supports($network)) {
                    $map[$network->value] = $publisher;
                }
            }
        }

        return $this->byNetwork = $map;
    }
}
