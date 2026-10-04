<?php

declare(strict_types=1);

namespace App\PublicApi\Webhook;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Anti-SSRF des webhooks partenaires (spec API partenaire v1, §3.3 et §3.6).
 *
 * Une URL de webhook est saisie par l'éditeur pour un TIERS ; le serveur qui l'appelle est à
 * l'intérieur du réseau. Sans ce contrôle, une URL `https://169.254.169.254/…` ou un nom qui résout
 * vers 10.0.0.1 ferait lire au partenaire des services internes à travers nous.
 *
 * Trois règles, et l'ordre compte :
 *  1. `https` seulement, sans identifiants dans l'URL ;
 *  2. le nom est RÉSOLU ici, et CHAQUE adresse rendue doit être publique (RFC 6890 « global ») — y
 *     compris l'IPv4 enfouie dans une IPv6 (`::ffff:a.b.c.d`, NAT64 `64:ff9b::/96`, 6to4 `2002::/16`) ;
 *  3. la connexion est ensuite ÉPINGLÉE sur l'adresse vérifiée (`resolve` du client HTTP) : pas de
 *     seconde résolution, donc pas de rebinding DNS entre la vérification et l'appel.
 */
final class WebhookDestinationGuard
{
    public function __construct(
        private readonly HostResolver $resolver,
    ) {
    }

    /** Règle 1 seulement (saisie) : rend l'hôte. Une IP littérale est jugée tout de suite. */
    public function assertAcceptableUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || 'https' !== strtolower($parts['scheme'] ?? '') || '' === ($parts['host'] ?? '')
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new UnprocessableEntityHttpException('L’adresse du webhook doit être une URL https, sans identifiants.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if (false !== filter_var($host, \FILTER_VALIDATE_IP) && !self::isPublicIp($host)) {
            throw new UnprocessableEntityHttpException('L’adresse du webhook vise un réseau privé, local ou réservé.');
        }

        return $host;
    }

    /**
     * Règles 1 et 2 (livraison) : l'adresse IP publique sur laquelle épingler la connexion.
     *
     * @throws UnsafeDestinationException
     */
    public function pin(string $url): string
    {
        try {
            $host = $this->assertAcceptableUrl($url);
        } catch (UnprocessableEntityHttpException $e) {
            throw new UnsafeDestinationException($e->getMessage());
        }

        $ips = false !== filter_var($host, \FILTER_VALIDATE_IP) ? [$host] : $this->resolver->resolve($host);
        if ([] === $ips) {
            throw new UnsafeDestinationException(sprintf('« %s » ne résout vers aucune adresse.', $host));
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new UnsafeDestinationException(sprintf('« %s » résout vers une adresse non publique.', $host));
            }
        }

        return $ips[0];
    }

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if (false === $packed) {
            return false;
        }

        if (16 === \strlen($packed)) {
            $embedded = match (true) {
                str_starts_with($packed, str_repeat("\0", 10)."\xff\xff") => substr($packed, 12, 4), // ::ffff:a.b.c.d
                str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\0", 8)) => substr($packed, 12, 4), // 64:ff9b::/96
                str_starts_with($packed, "\x20\x02") => substr($packed, 2, 4), // 2002::/16 (6to4)
                default => null,
            };
            if (null !== $embedded) {
                return self::isPublicIp((string) inet_ntop($embedded));
            }
        }

        return false !== filter_var(inet_ntop($packed), \FILTER_VALIDATE_IP, \FILTER_FLAG_GLOBAL_RANGE);
    }
}
