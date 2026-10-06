<?php

declare(strict_types=1);

namespace App\PublicApi\Webhook;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Un POST signé vers un webhook partenaire, épinglé sur l'adresse vérifiée par l'anti-SSRF.
 *
 * Signature : `Fluvia-Signature: t=<horodatage>,v1=<hex HMAC-SHA256(secret, "<t>.<corps>")>`. Le
 * partenaire recalcule le HMAC sur le corps BRUT reçu et refuse au-delà de 5 minutes d'écart sur `t`
 * (rejeu). `Idempotency-Key` porte l'identifiant de l'événement : un réessai le répète à l'identique.
 *
 * ⚠ **AUCUNE REDIRECTION SUIVIE** (`max_redirects: 0`) : une redirection vers une adresse interne
 * contournerait l'épinglage. Un 3xx est un échec, comme un 4xx/5xx.
 *
 * Délais : `timeout` (inactivité) 3 s — il borne l'établissement de la connexion, où rien ne passe ;
 * `max_duration` 8 s — 3 s de connexion et 5 s de réponse au plus. Aucun mandataire (`no_proxy`) : un
 * proxy résoudrait le nom de son côté et l'épinglage ne vaudrait plus rien.
 */
final class WebhookSender
{
    public const SIGNATURE_HEADER = 'Fluvia-Signature';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly WebhookDestinationGuard $guard,
    ) {
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function send(string $url, string $secret, string $body, string $eventId): array
    {
        try {
            $ip = $this->guard->pin($url);
        } catch (UnsafeDestinationException $e) {
            return ['ok' => false, 'error' => 'Destination refusée : '.$e->getMessage()];
        }

        $host = (string) parse_url($url, \PHP_URL_HOST);
        try {
            $response = $this->http->request('POST', $url, self::options($host, $ip, $secret, $body, $eventId, time()));
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => sprintf('Injoignable (%s) — hôte %s.', self::classify($e), $host)];
        }

        return $status >= 200 && $status < 300
            ? ['ok' => true, 'error' => null]
            : ['ok' => false, 'error' => sprintf('Réponse HTTP %d%s.', $status, $status >= 300 && $status < 400 ? ' (redirection non suivie)' : '')];
    }

    /**
     * ⚠ LA CLASSE DE L'ERREUR, JAMAIS SON MESSAGE. Les exceptions de transport du client HTTP contiennent
     * l'URL COMPLÈTE (« Idle timeout reached for "https://…?token=…" ») : recopiée, elle finissait en base,
     * dans la fiche éditeur, dans le journal du worker et dans l'ErrorDetailsStamp de Messenger. On ne garde
     * qu'une catégorie, et l'hôte.
     */
    public static function classify(\Throwable $e): string
    {
        $text = strtolower($e->getMessage());

        return match (true) {
            str_contains($text, 'timeout') || str_contains($text, 'timed out') => 'délai dépassé',
            str_contains($text, 'resolve') => 'résolution DNS',
            str_contains($text, 'ssl') || str_contains($text, 'tls') || str_contains($text, 'certificate') => 'TLS',
            str_contains($text, 'refused') || str_contains($text, 'connect') => 'connexion refusée',
            default => 'erreur de transport',
        };
    }

    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$body, $secret));
    }

    /** @return array<string, mixed> les options exactes de l'appel — publiques pour que les tests les éprouvent */
    public static function options(string $host, string $ip, string $secret, string $body, string $eventId, int $timestamp): array
    {
        return [
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Fluvia-Webhooks/1',
                self::SIGNATURE_HEADER => self::signature($secret, $timestamp, $body),
                'Idempotency-Key' => $eventId,
            ],
            'resolve' => [trim($host, '[]') => $ip],
            'max_redirects' => 0,
            'no_proxy' => '*',
            'timeout' => 3,
            'max_duration' => 8,
        ];
    }
}
