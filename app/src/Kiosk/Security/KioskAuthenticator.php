<?php

declare(strict_types=1);

namespace App\Kiosk\Security;

use App\Kiosk\Entity\Kiosk;
use App\Kiosk\Service\KioskSecrets;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authentifie l'APPAREIL d'une borne sur le pare-feu `kiosk` (`^/api/kiosk/`, voir security.yaml),
 * patron `App\Acces\Security\TerminalAuthenticator` : le jeton se retrouve par son empreinte SHA-256,
 * la borne doit être ACTIVE, et « jeton inconnu », « borne désappairée » et « borne désactivée » rendent
 * le même 401 — l'écran de la borne revient alors à l'appairage, et personne n'apprend lequel des trois.
 *
 * Seule exception : `POST /api/kiosk/pair`, qui ÉCHANGE un code d'appairage contre ce jeton. Elle n'a
 * pas encore de jeton à présenter ; elle est publique (security.yaml) et bornée par adresse.
 */
final class KioskAuthenticator extends AbstractAuthenticator
{
    public const PAIR_PATH = '/api/kiosk/pair';
    private const GENERIC_MESSAGE = 'Borne non appairée, désappairée ou désactivée.';
    private const LAST_SEEN_THROTTLE_SECONDS = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly KioskSecrets $secrets,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->getPathInfo() !== self::PAIR_PATH;
    }

    public function authenticate(Request $request): Passport
    {
        $header = (string) $request->headers->get('Authorization', '');
        $token = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';
        if ($token === '') {
            throw new CustomUserMessageAuthenticationException(self::GENERIC_MESSAGE);
        }

        $kiosk = $this->em->getRepository(Kiosk::class)->findOneBy(['deviceTokenHash' => $this->secrets->hashDeviceToken($token)]);
        if (!$kiosk instanceof Kiosk || !$kiosk->isActive()) {
            throw new CustomUserMessageAuthenticationException(self::GENERIC_MESSAGE);
        }

        return new SelfValidatingPassport(
            new UserBadge('kiosk:' . $kiosk->getId(), static fn (): KioskPrincipal => new KioskPrincipal($kiosk)),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        if (!$user instanceof KioskPrincipal) {
            return null;
        }
        $now = new \DateTimeImmutable();
        $last = $user->kiosk->getLastSeenAt();
        if ($last !== null && $last > $now->modify(sprintf('-%d seconds', self::LAST_SEEN_THROTTLE_SECONDS))) {
            return null;
        }
        // Au mieux : la supervision tolère un retard, jamais un échec de la vente.
        try {
            $this->connection->executeStatement(
                'UPDATE kiosk SET last_seen_at = :now WHERE id = UNHEX(:hex)',
                ['now' => $now->format('Y-m-d H:i:s'), 'hex' => bin2hex($user->kiosk->getId()->toBinary())],
            );
        } catch (\Throwable) {
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['message' => self::GENERIC_MESSAGE, 'code' => 'kiosk_not_paired'], Response::HTTP_UNAUTHORIZED);
    }
}
