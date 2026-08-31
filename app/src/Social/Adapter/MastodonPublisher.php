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
 * Publication sur Mastodon (SOC-2) — `POST {instance}/api/v1/statuses`.
 *
 * Mastodon est un réseau **ouvert** : aucune vérification d'entreprise, aucune revue applicative. C'est
 * ce qui permet de prouver ici toute la mécanique — file, reprises, classement des erreurs — pendant
 * que les revues des plateformes fermées sont en cours (D14, séquencement).
 *
 * **`Idempotency-Key` n'est pas un détail.** Mastodon dédoublonne les statuts portant la même clé
 * pendant plusieurs heures. Sans elle, une coupure réseau après l'envoi mais avant la réponse ferait
 * republier le même message à la reprise : l'établissement paraîtrait deux fois sur son propre fil,
 * et personne ne saurait dire pourquoi. On y met l'identifiant de la publication, qui est stable
 * d'une tentative à l'autre — c'est précisément ce à quoi il sert.
 */
final class MastodonPublisher implements SocialPublisher
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function supports(SocialNetwork $network): bool
    {
        return $network === SocialNetwork::Mastodon;
    }

    public function publish(PublicationRequest $request): PublicationOutcome
    {
        $host = $request->host;
        if ($host === null || trim($host) === '') {
            // Mastodon est fédéré : le jeton ne vaut que pour l'instance qui l'a émis. Publier sans
            // savoir où n'a pas de sens, et réessayer n'en donnera pas.
            throw SocialPublishingException::permanent('missing_host', 'Aucune instance Mastodon connue pour ce compte.');
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($host, '/') . '/api/v1/statuses', [
                'auth_bearer' => $request->accessToken,
                'headers' => ['Idempotency-Key' => $request->idempotencyKey],
                'json' => ['status' => $request->body],
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw $this->fromStatus($status);
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            // Coupure, DNS, délai dépassé : rien ne dit que le message n'est pas parti, d'où
            // l'importance de la clé d'idempotence ci-dessus.
            throw SocialPublishingException::retryable('transport_error', $e->getMessage());
        }

        $remoteId = $payload['id'] ?? null;
        if (!is_scalar($remoteId) || (string) $remoteId === '') {
            // Un 2xx sans identifiant : on ne peut ni prouver la parution, ni aller chercher les
            // statistiques plus tard. Le déclarer publié serait un mensonge dans l'historique.
            throw SocialPublishingException::permanent('missing_remote_id', 'Mastodon a accepté sans rendre d identifiant.');
        }

        $url = $payload['url'] ?? null;

        return new PublicationOutcome((string) $remoteId, is_string($url) ? $url : null);
    }

    private function fromStatus(int $status): SocialPublishingException
    {
        return match (true) {
            // Le jeton est refusé : le compte devra être reconnecté, et le handler le marquera.
            $status === 401 || $status === 403 => SocialPublishingException::permanent('unauthorized', 'Mastodon a refusé le jeton.'),
            // Quota : cela repassera. C'est le cas que D14 cite en exemple, et le motif pour lequel la
            // file existe.
            $status === 429 => SocialPublishingException::retryable('rate_limited', 'Quota Mastodon dépassé.'),
            $status >= 500 => SocialPublishingException::retryable('server_error', sprintf('Mastodon a répondu %d.', $status)),
            default => SocialPublishingException::permanent('rejected', sprintf('Mastodon a refusé la publication (%d).', $status)),
        };
    }
}
