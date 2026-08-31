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
 * Statistiques d'un enregistrement Bluesky (SOC-3) — `app.bsky.feed.getPosts`, précédé de l'ouverture
 * de session, comme pour la publication : le coffre détient un mot de passe d'application, pas un
 * jeton durable.
 *
 * Bluesky rend `likeCount`, `repostCount` et `replyCount`, et **aucune portée** — `impressions` reste
 * nul. Un tableau `posts` vide signifie que l'enregistrement n'existe plus : c'est définitif, on ne le
 * redemandera pas indéfiniment.
 */
final class BlueskyMetricsCollector implements SocialMetricsCollector
{
    private const DEFAULT_HOST = 'https://bsky.social';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function supports(SocialNetwork $network): bool
    {
        return $network === SocialNetwork::Bluesky;
    }

    public function collect(MetricsRequest $request): CollectedMetrics
    {
        $host = rtrim($request->host ?? self::DEFAULT_HOST, '/');
        $jwt = $this->openSession($host, $request);

        try {
            $response = $this->httpClient->request('GET', $host . '/xrpc/app.bsky.feed.getPosts', [
                'auth_bearer' => $jwt,
                'query' => ['uris' => $request->remotePostId],
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw $this->fromStatus($status);
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw SocialPublishingException::retryable('transport_error', $e->getMessage());
        }

        $posts = $payload['posts'] ?? [];
        if (!is_array($posts) || $posts === []) {
            throw SocialPublishingException::permanent('remote_post_gone', 'L enregistrement n existe plus chez Bluesky.');
        }

        /** @var array<string, mixed> $post */
        $post = is_array($posts[0] ?? null) ? $posts[0] : [];

        return new CollectedMetrics(
            likes: $this->intOrNull($post, 'likeCount'),
            shares: $this->intOrNull($post, 'repostCount'),
            replies: $this->intOrNull($post, 'replyCount'),
            impressions: null,
            raw: $post,
        );
    }

    private function openSession(string $host, MetricsRequest $request): string
    {
        try {
            $response = $this->httpClient->request('POST', $host . '/xrpc/com.atproto.server.createSession', [
                'json' => ['identifier' => $request->handle, 'password' => $request->accessToken],
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw $this->fromStatus($status);
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw SocialPublishingException::retryable('transport_error', $e->getMessage());
        }

        $jwt = $payload['accessJwt'] ?? null;
        if (!is_string($jwt) || $jwt === '') {
            throw SocialPublishingException::permanent('missing_session_token', 'Bluesky a ouvert une session sans jeton.');
        }

        return $jwt;
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
            $status === 401 || $status === 403 => SocialPublishingException::permanent('unauthorized', 'Bluesky a refusé le mot de passe d application.'),
            $status === 429 => SocialPublishingException::retryable('rate_limited', 'Quota Bluesky dépassé.'),
            $status >= 500 => SocialPublishingException::retryable('server_error', sprintf('Bluesky a répondu %d.', $status)),
            default => SocialPublishingException::permanent('rejected', sprintf('Bluesky a refusé la lecture (%d).', $status)),
        };
    }
}
