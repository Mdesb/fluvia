<?php

declare(strict_types=1);

namespace App\Social\Port;

use App\Social\Dto\CollectedMetrics;
use App\Social\Dto\MetricsRequest;
use App\Social\Enum\SocialNetwork;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Port de collecte des statistiques — un collecteur par réseau (SOC-3).
 *
 * Séparé de `SocialPublisher` volontairement : publier et mesurer n'ont ni la même fréquence, ni les
 * mêmes quotas, ni le même droit d'échouer. Une collecte qui rate n'est pas un incident — on
 * réessaiera au prochain passage ; une publication qui rate en est un. Les mêler dans une seule
 * interface ferait porter à la publication la fragilité de la mesure.
 */
#[AutoconfigureTag('social.metrics_collector')]
interface SocialMetricsCollector
{
    public function supports(SocialNetwork $network): bool;

    /**
     * @throws \App\Social\Exception\SocialPublishingException si le réseau refuse ou ne répond pas
     */
    public function collect(MetricsRequest $request): CollectedMetrics;
}
