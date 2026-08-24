<?php

declare(strict_types=1);

namespace App\Social\Adapter;

use App\Social\Dto\CollectedMetrics;
use App\Social\Dto\MetricsRequest;
use App\Social\Enum\SocialNetwork;
use App\Social\Exception\SocialPublishingException;
use App\Social\Port\SocialMetricsCollector;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Statistiques d'un statut Mastodon (SOC-3) — `GET {instance}/api/v1/statuses/{id}`.
 *
 * Mastodon rend `favourites_count`, `reblogs_count` et `replies_count`. Il ne rend **aucune portée** :
 * `impressions` reste nul, et c'est un fait à conserver plutôt qu'un zéro à inventer.
 *
 * Le 404 est définitif : un statut supprimé chez le réseau ne réapparaîtra pas, et le redemander
 * toutes les heures pendant des mois consommerait le quota de l'établissement pour rien.
 */
final class MastodonMetricsCollector implements SocialMetricsCollector
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function supports(SocialNetwork $network): bool
    {
        return $network === SocialNetwork::Mastodon;
    }

    public function collect(MetricsRequest $request): CollectedMetrics
    {
        $host = $request->host;
        if ($host === null || trim($host) === '') {
            throw SocialPublishingException::permanent('missing_host', 'Aucune instance Mastodon connue pour ce compte.');
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                rtrim($host, '/') . '/api/v1/statuses/' . rawurlencode($request->remotePostId),
                ['auth_bearer' => $request->accessToken],
            );

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw $this->fromStatus($status);
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw SocialPublishingException::retryable('transport_error', $e->getMessage());
        }

        return new CollectedMetrics(
            likes: $this->intOrNull($payload, 'favourites_count'),
            shares: $this->intOrNull($payload, 'reblogs_count'),
            replies: $this->intOrNull($payload, 'replies_count'),
            impressions: null,
            raw: $payload,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function intOrNull(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function fromStatus(int $status): SocialPublishingException
    {
        return match (true) {
            $status === 401 || $status === 403 => SocialPublishingException::permanent('unauthorized', 'Mastodon a refusé le jeton.'),
            $status === 404 => SocialPublishingException::permanent('remote_post_gone', 'Le statut n existe plus chez Mastodon.'),
            $status === 429 => SocialPublishingException::retryable('rate_limited', 'Quota Mastodon dépassé.'),
            $status >= 500 => SocialPublishingException::retryable('server_error', sprintf('Mastodon a répondu %d.', $status)),
            default => SocialPublishingException::permanent('rejected', sprintf('Mastodon a refusé la lecture (%d).', $status)),
        };
    }
}
