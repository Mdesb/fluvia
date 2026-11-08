<?php

declare(strict_types=1);

namespace App\Kiosk\Service;

use App\Kiosk\Entity\Kiosk;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Les deux portes de l'APPAREIL qui ne vendent rien : l'appairage, et le code de sortie du personnel.
 *
 * ── L'APPAIRAGE ─────────────────────────────────────────────────────────────────────────────────
 *
 * Un code valide, non expiré, d'une borne active, s'échange UNE fois contre un jeton d'appareil. Le
 * code est effacé à l'échange ; un ancien appareil perd son jeton (un seul appareil par borne). Un code
 * faux, expiré ou d'une borne désactivée rend la même réponse. La route est publique : elle est bornée
 * par adresse (`kiosk_pairing`).
 *
 * ── LA SORTIE DU MODE BORNE ─────────────────────────────────────────────────────────────────────
 *
 * Le code du personnel se vérifie ICI, jamais dans le navigateur : un code comparé côté écran se lit
 * dans le code source de la page. Cinq échecs d'affilée ferment la porte cinq minutes ; un succès
 * remet le compteur à zéro. La réponse ne dit pas combien d'essais il reste.
 */
final class KioskDeviceAccess
{
    public const MAX_FAILED_UNLOCKS = 5;
    public const UNLOCK_BLOCK_MINUTES = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KioskSecrets $secrets,
        #[Autowire(service: 'limiter.kiosk_pairing')]
        private readonly RateLimiterFactory $pairingLimiter,
    ) {
    }

    /** @return array{token: string, kiosk: array{id: string, label: string}} */
    public function pair(string $typedCode, ?string $clientIp): array
    {
        $limit = $this->pairingLimiter->create($clientIp ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Trop d\'essais d\'appairage depuis cette adresse. Réessayez dans quelques minutes.');
        }

        $refused = new UnprocessableEntityHttpException('Code d\'appairage inconnu ou expiré : demandez-en un nouveau dans Paramètres › Bornes.');
        if ($this->secrets->normalizePairingCode($typedCode) === '') {
            throw $refused;
        }
        $kiosk = $this->em->getRepository(Kiosk::class)->findOneBy(['pairingCodeHash' => $this->secrets->hashPairingCode($typedCode)]);
        $now = new \DateTimeImmutable();
        if (!$kiosk instanceof Kiosk || !$kiosk->isActive()
            || $kiosk->getPairingCodeExpiresAt() === null || $kiosk->getPairingCodeExpiresAt() < $now) {
            throw $refused;
        }

        $token = $this->secrets->newDeviceToken();
        $kiosk->pair($this->secrets->hashDeviceToken($token), $now);
        $this->em->flush();

        return ['token' => $token, 'kiosk' => ['id' => (string) $kiosk->getId(), 'label' => $kiosk->getLabel()]];
    }

    public function unlock(Kiosk $kiosk, string $typedCode): void
    {
        $now = new \DateTimeImmutable();
        $blockedUntil = $kiosk->getUnlockBlockedUntil();
        if ($blockedUntil !== null && $blockedUntil > $now) {
            throw new TooManyRequestsHttpException($blockedUntil->getTimestamp() - $now->getTimestamp(), 'Trop d\'essais : le déverrouillage est fermé quelques minutes.');
        }

        if ($this->secrets->exitCodeMatches($typedCode, $kiosk->getExitCodeHash())) {
            $kiosk->setFailedUnlocks(0)->setUnlockBlockedUntil(null);
            $this->em->flush();

            return;
        }

        $failures = $kiosk->getFailedUnlocks() + 1;
        if ($failures >= self::MAX_FAILED_UNLOCKS) {
            $kiosk->setFailedUnlocks(0)->setUnlockBlockedUntil($now->modify(sprintf('+%d minutes', self::UNLOCK_BLOCK_MINUTES)));
        } else {
            $kiosk->setFailedUnlocks($failures);
        }
        $this->em->flush();

        throw new AccessDeniedHttpException('Code incorrect.');
    }
}
