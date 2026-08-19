<?php

declare(strict_types=1);

namespace App\Acces\Security;

use App\Acces\Entity\JetonTerminal;
use App\Acces\Entity\Terminal;
use App\Acces\Enum\StatutJetonTerminal;
use App\Acces\Enum\StatutTerminal;
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
 * Authentification d'un `Terminal` (US-TERM-01/09, plan-acces-terminal.md §3.1). Firewall dédié
 * `terminal` (`^/api/terminal`, cf. security.yaml), strictement isolé du firewall `api` (JWT
 * `Utilisateur`) : pattern évalué en premier, zéro risque de collision. Résout le jeton par hash
 * (`hash('sha256', $secret)` — recherche exacte indexée), vérifie `statut = actif` (jeton **et**
 * terminal), sans distinguer « jeton inconnu » de « jeton révoqué/expiré » dans la réponse (401
 * générique — CA-1/CA-11, évite la fuite d'information, §4.1/§7 spec).
 *
 * Toujours sollicité sur ce firewall (`supports()` renvoie `true`) : un appel sans en-tête
 * `Authorization` déclenche aussi le 401 générique (au lieu d'un 403 « anonyme », cf. CA-1/CA-11).
 */
final class TerminalAuthenticator extends AbstractAuthenticator
{
    private const MESSAGE_GENERIQUE = 'Jeton terminal invalide, inconnu ou révoqué.';

    /** Throttle de l'écriture `dernierAppel` (§3.1 du plan) : au plus 1 UPDATE / 30 s par terminal. */
    private const INTERVALLE_THROTTLE_SECONDES = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
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
        if ($secret === '') {
            throw new CustomUserMessageAuthenticationException(self::MESSAGE_GENERIQUE);
        }

        $hash = hash('sha256', $secret);
        $jeton = $this->em->getRepository(JetonTerminal::class)->findOneBy(['secretHash' => $hash]);
        if (!$jeton instanceof JetonTerminal || $jeton->getStatut() !== StatutJetonTerminal::Actif) {
            throw new CustomUserMessageAuthenticationException(self::MESSAGE_GENERIQUE);
        }
        if ($jeton->getDateExpiration() !== null && $jeton->getDateExpiration() < new \DateTimeImmutable()) {
            throw new CustomUserMessageAuthenticationException(self::MESSAGE_GENERIQUE);
        }

        $terminal = $jeton->getTerminal();
        if (!$terminal instanceof Terminal || $terminal->getStatut() !== StatutTerminal::Actif) {
            throw new CustomUserMessageAuthenticationException(self::MESSAGE_GENERIQUE);
        }

        return new SelfValidatingPassport(
            new UserBadge((string) $terminal->getId(), static fn (): TerminalUtilisateur => new TerminalUtilisateur($terminal)),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        if (!$user instanceof TerminalUtilisateur) {
            return null;
        }

        $maintenant = new \DateTimeImmutable();

        // Throttle best-effort : une borne peut appeler `/terminal/snapshot`/`/terminal/passages` très
        // fréquemment (polling) — sans throttle, chaque appel déclenche un UPDATE synchrone. La valeur
        // observée provient de l'entité déjà chargée par `authenticate()` (avant tout écriture de cette
        // méthode), donc représentative du dernier appel réellement journalisé.
        $dernierAppel = $user->terminal->getDernierAppel();
        if ($dernierAppel instanceof \DateTimeImmutable
            && $dernierAppel > $maintenant->modify(sprintf('-%d seconds', self::INTERVALLE_THROTTLE_SECONDES))
        ) {
            return null;
        }

        // Écriture best-effort (§3.1 du plan) : ne bloque jamais la réponse HTTP, échec silencieux.
        try {
            $this->connection->executeStatement(
                'UPDATE acces_terminal SET dernier_appel = :now, dernier_appel_reussi = 1 WHERE id = UNHEX(:hex)',
                [
                    'now' => $maintenant->format('Y-m-d H:i:s'),
                    'hex' => bin2hex($user->terminal->getId()->toBinary()),
                ],
            );
        } catch (\Throwable) {
            // Best-effort : la supervision (A-03) tolère un retard, jamais un blocage du chemin < 1 s.
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['message' => self::MESSAGE_GENERIQUE], JsonResponse::HTTP_UNAUTHORIZED);
    }
}
