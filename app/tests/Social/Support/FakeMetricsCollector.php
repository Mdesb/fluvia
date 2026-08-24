<?php

declare(strict_types=1);

namespace App\Tests\Social\Support;

use App\Social\Dto\CollectedMetrics;
use App\Social\Dto\MetricsRequest;
use App\Social\Enum\SocialNetwork;
use App\Social\Port\SocialMetricsCollector;

/**
 * Collecteur de statistiques pilotable, pour éprouver le handler sans réseau (SOC-3).
 */
final class FakeMetricsCollector implements SocialMetricsCollector
{
    public int $calls = 0;

    /** @var list<MetricsRequest> */
    public array $received = [];

    private ?\Throwable $toThrow = null;

    private CollectedMetrics $metrics;

    public function __construct()
    {
        $this->metrics = new CollectedMetrics(
            likes: 12,
            shares: 3,
            replies: 1,
            impressions: null,
            raw: ['favourites_count' => 12, 'reblogs_count' => 3, 'replies_count' => 1],
        );
    }

    public function willThrow(\Throwable $e): self
    {
        $this->toThrow = $e;

        return $this;
    }

    public function willReturn(CollectedMetrics $metrics): self
    {
        $this->metrics = $metrics;
        $this->toThrow = null;

        return $this;
    }

    public function supports(SocialNetwork $network): bool
    {
        return true;
    }

    public function collect(MetricsRequest $request): CollectedMetrics
    {
        ++$this->calls;
        $this->received[] = $request;

        if ($this->toThrow !== null) {
            throw $this->toThrow;
        }

        return $this->metrics;
    }
}
