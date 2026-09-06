<?php

declare(strict_types=1);

namespace App\PublicApi\Security;

use App\PublicApi\Entity\ApiCredential;
use App\PublicApi\Entity\ApiGrant;
use App\PublicApi\Enum\CredentialStatus;
use App\PublicApi\Enum\GrantStatus;
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
 * Authentification d'une application tierce sur `/v1` (T1, D101/D102).
 *
 * Meme patron que {@see \App\Acces\Security\TerminalAuthenticator}, qui l'a eprouve pour les
 * bornes : en-tete `Authorization: Bearer <secret>`, resolution par `hash('sha256', $secret)` —
 * recherche exacte indexee — puis controle du statut de la cle ET de l'application.
 *
 * ⚠ **LE PARE-FEU EST PLACE AVANT `api`, ET C'EST STRUCTUREL.** Symfony evalue les pare-feux dans
 * l'ordre et s'arrete au premier motif qui correspond : `^/v1` capture toute l'API publique avant
 * que l'authentificateur JWT humain ne la voie. Si l'ordre s'inversait, un partenaire pourrait
 * presenter un JWT humain sur `/v1`.
 *
 * ⚠ **UN SEUL MESSAGE D'ECHEC, POUR TOUS LES CAS.** Cle inconnue, revoquee, expiree, application
 * desactivee, aucun consentement : la reponse est identique. Distinguer « cle inconnue » de « cle
 * revoquee » dirait a qui essaie des cles laquelle a deja existe.
 */
final class PublicApiAuthenticator extends AbstractAuthenticator
{
    private const MESSAGE_GENERIQUE = 'Clé d\'API invalide, inconnue ou révoquée.';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $header = (string) $request->headers->get('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            throw new CustomUserMessageAuthenticationException(self::MESSAGE_GENERIQUE);
        }

        $secret = trim(substr($header, 7));

        if ('' === $secret) {
            throw new CustomUserMessageAuthenticationException(self::MESSAGE_GENERIQUE);
        }

        $credential = $this->em->getRepository(ApiCredential::class)
            ->findOneBy(['secretHash' => hash('sha256', $secret)]);

        if (!$credential instanceof ApiCredential || CredentialStatus::Active !== $credential->getStatus()) {
            throw new CustomUserMessageAuthenticationException(self::MESSAGE_GENERIQUE);
        }

        $expiration = $credential->getExpiresAt();

        if (null !== $expiration && $expiration < new \DateTimeImmutable()) {
            throw new CustomUserMessageAuthenticationException(self::MESSAGE_GENERIQUE);
        }

        $application = $credential->getApplication();

        if (null === $application || !$application->isActive()) {
            throw new CustomUserMessageAuthenticationException(self::MESSAGE_GENERIQUE);
        }

        /** @var list<ApiGrant> $grants */
        $grants = $this->em->getRepository(ApiGrant::class)->findBy([
            'application' => $application,
            'status' => GrantStatus::Active,
        ]);

        return new SelfValidatingPassport(
            new UserBadge(
                (string) $application->getId(),
                static fn (): PartnerUser => new PartnerUser($application, $grants),
            ),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['message' => self::MESSAGE_GENERIQUE], JsonResponse::HTTP_UNAUTHORIZED);
    }
}
