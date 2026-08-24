<?php

declare(strict_types=1);

namespace App\Social\Adapter;

use App\Social\Dto\PublicationOutcome;
use App\Social\Dto\PublicationRequest;
use App\Social\Enum\SocialNetwork;
use App\Social\Exception\SocialPublishingException;
use App\Social\Port\SocialPublisher;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Publication sur Bluesky (SOC-2) — protocole AT, en deux appels.
 *
 * **Pourquoi deux appels et pas un.** Bluesky n'a pas de jeton d'accès durable au sens OAuth : ce que
 * le coffre détient est un *mot de passe d'application*, avec lequel on ouvre une session
 * (`com.atproto.server.createSession`) qui rend un jeton de courte durée, puis on écrit l'enregistrement
 * (`com.atproto.repo.createRecord`). Le mot de passe d'application est révocable individuellement chez
 * Bluesky, ce qui est exactement la propriété qu'on veut pour un jeton qu'on stocke : le révoquer ne
 * coupe pas le compte de son propriétaire.
 *
 * **Une limite qu'il faut connaître avant d'en dépendre** : le protocole AT n'offre pas de clé
 * d'idempotence. Contrairement à Mastodon, une coupure survenue après l'écriture mais avant la réponse
 * peut donc produire un doublon à la reprise. C'est la raison pour laquelle on ne réessaie **que** sur
 * les erreurs de transport et les refus temporaires, jamais sur un refus applicatif — et c'est un point
 * qui doit figurer dans la spécification plutôt que d'être découvert par un client.
 */
final class BlueskyPublisher implements SocialPublisher
{
    private const DEFAULT_HOST = 'https://bsky.social';
    private const COLLECTION = 'app.bsky.feed.post';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function supports(SocialNetwork $network): bool
    {
        return $network === SocialNetwork::Bluesky;
    }

    public function publish(PublicationRequest $request): PublicationOutcome
    {
        $host = rtrim($request->host ?? self::DEFAULT_HOST, '/');

        $session = $this->openSession($host, $request);
        $uri = $this->createRecord($host, $request, $session);

        return new PublicationOutcome($uri, $this->webUrl($request->handle, $uri));
    }

    /**
     * @return array{accessJwt: string, did: string}
     */
    private function openSession(string $host, PublicationRequest $request): array
    {
        try {
            $response = $this->httpClient->request('POST', $host . '/xrpc/com.atproto.server.createSession', [
                'json' => [
                    'identifier' => $request->handle,
                    'password' => $request->accessToken,
                ],
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw $this->fromStatus($status, 'session');
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw SocialPublishingException::retryable('transport_error', $e->getMessage());
        }

        $jwt = $payload['accessJwt'] ?? null;
        $did = $payload['did'] ?? $request->remoteAccountId;
        if (!is_string($jwt) || $jwt === '') {
            throw SocialPublishingException::permanent('missing_session_token', 'Bluesky a ouvert une session sans jeton.');
        }

        return ['accessJwt' => $jwt, 'did' => is_string($did) && $did !== '' ? $did : $request->remoteAccountId];
    }

    /**
     * @param array{accessJwt: string, did: string} $session
     */
    private function createRecord(string $host, PublicationRequest $request, array $session): string
    {
        try {
            $response = $this->httpClient->request('POST', $host . '/xrpc/com.atproto.repo.createRecord', [
                'auth_bearer' => $session['accessJwt'],
                'json' => [
                    'repo' => $session['did'],
                    'collection' => self::COLLECTION,
                    'record' => [
                        '$type' => self::COLLECTION,
                        'text' => $request->body,
                        // Bluesky exige la date de création dans l'enregistrement lui-même. Aucune
                        // assertion d'horloge n'est faite là-dessus dans la suite (D20) : les tests
                        // vérifient le texte et l'identifiant rendu, pas l'instant.
                        'createdAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                    ],
                ],
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw $this->fromStatus($status, 'record');
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw SocialPublishingException::retryable('transport_error', $e->getMessage());
        }

        $uri = $payload['uri'] ?? null;
        if (!is_string($uri) || $uri === '') {
            throw SocialPublishingException::permanent('missing_remote_id', 'Bluesky a accepté sans rendre d URI.');
        }

        return $uri;
    }

    /**
     * L'URI AT (`at://did:plc:.../app.bsky.feed.post/<rkey>`) n'est pas cliquable. L'adresse web se
     * reconstruit depuis le pseudonyme et la dernière partie de l'URI — si la forme change, on rend
     * `null` plutôt qu'un lien faux : un lien mort dans un historique fait douter de tout le reste.
     */
    private function webUrl(string $handle, string $uri): ?string
    {
        $rkey = substr($uri, strrpos($uri, '/') === false ? 0 : strrpos($uri, '/') + 1);
        if ($rkey === '' || $handle === '') {
            return null;
        }

        return sprintf('https://bsky.app/profile/%s/post/%s', ltrim($handle, '@'), $rkey);
    }

    private function fromStatus(int $status, string $step): SocialPublishingException
    {
        return match (true) {
            $status === 401 || $status === 403 => SocialPublishingException::permanent(
                'unauthorized',
                sprintf('Bluesky a refusé le mot de passe d application (étape %s).', $step),
            ),
            $status === 429 => SocialPublishingException::retryable('rate_limited', 'Quota Bluesky dépassé.'),
            $status >= 500 => SocialPublishingException::retryable('server_error', sprintf('Bluesky a répondu %d (étape %s).', $status, $step)),
            default => SocialPublishingException::permanent('rejected', sprintf('Bluesky a refusé (%d, étape %s).', $status, $step)),
        };
    }
}
