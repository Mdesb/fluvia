<?php

declare(strict_types=1);

namespace App\Social\Service;

use App\Social\Crypto\SocialTokenCipher;
use App\Social\Entity\SocialAccount;
use App\Social\Exception\SocialTokenCipherException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rechiffre les jetons du coffre social avec la clé active (SOC-1, mécanisme de rotation).
 *
 * **Pourquoi un service et pas seulement une commande.** La rotation est le geste le plus risqué du
 * module : mal faite, elle rend illisibles les jetons de tous les établissements, et personne ne s'en
 * aperçoit avant la première publication. Elle doit donc être éprouvable sans console — un test qui
 * passe par la sortie d'une commande vérifie l'affichage, pas le rechiffrement.
 *
 * **Idempotent et reprenable.** Chaque passage ne touche que ce qui n'est pas déjà à la version
 * active. On peut relancer autant de fois qu'on veut, interrompre, reprendre : le critère d'arrêt est
 * un compteur qui tombe à zéro, jamais une durée ni une conviction.
 *
 * **Ce service ne journalise aucun jeton**, ni en clair ni chiffré — seulement des compteurs. Une
 * rotation est précisément le moment où l'on est tenté d'afficher « avant / après » pour se rassurer,
 * et c'est précisément le moment où cela ferait fuir la totalité du coffre dans un journal.
 */
final class SocialTokenKeyRotator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialTokenCipher $cipher,
    ) {
    }

    /**
     * Répartition des jetons par version de clé.
     *
     * C'est ce qu'on regarde **avant** de retirer une clé de l'environnement. Le faire après
     * reviendrait à découvrir la perte au premier envoi, c'est-à-dire chez le client.
     *
     * @return array{byVersion: array<int, int>, unreadable: int, total: int}
     */
    public function status(): array
    {
        $byVersion = [];
        $unreadable = 0;
        $total = 0;

        foreach ($this->allStoredValues() as $value) {
            ++$total;
            $version = $this->cipher->keyVersionOf($value);
            $byVersion[$version] = ($byVersion[$version] ?? 0) + 1;

            if (!\in_array($version, $this->cipher->knownVersions(), true)) {
                ++$unreadable;
            }
        }

        ksort($byVersion);

        return ['byVersion' => $byVersion, 'unreadable' => $unreadable, 'total' => $total];
    }

    /**
     * Rechiffre au plus `$limit` comptes restant à la traîne.
     *
     * Le compte est l'unité de travail, pas le jeton : les deux jetons d'un même compte sont
     * rechiffrés ensemble et enregistrés ensemble. Les traiter séparément laisserait, en cas
     * d'interruption, un compte dont l'accès est à la nouvelle version et le rafraîchissement à
     * l'ancienne — lisible tant que les deux clés sont déclarées, illisible dès qu'on retire
     * l'ancienne, et invisible d'ici là.
     *
     * @return array{rotated: int, failed: int, remaining: int}
     */
    public function rotate(int $limit, bool $dryRun = false): array
    {
        $prefix = $this->cipher->currentVersionPrefix();

        /** @var list<SocialAccount> $accounts */
        $accounts = $this->em->createQueryBuilder()
            ->select('a')
            ->from(SocialAccount::class, 'a')
            ->where('a.accessTokenEncrypted IS NOT NULL AND a.accessTokenEncrypted NOT LIKE :prefix')
            ->orWhere('a.refreshTokenEncrypted IS NOT NULL AND a.refreshTokenEncrypted NOT LIKE :prefix')
            ->setParameter('prefix', $prefix . '%')
            ->orderBy('a.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $rotated = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $access = $this->reencrypted($account->getAccessTokenEncrypted());
                $refresh = $this->reencrypted($account->getRefreshTokenEncrypted());
            } catch (SocialTokenCipherException) {
                // Une clé manquante ou une valeur corrompue : on compte et on continue. S'arrêter au
                // premier accroc laisserait le reste du parc à l'ancienne version pour une seule ligne
                // abîmée, et c'est le parc qui compte. Le décompte final le dira.
                ++$failed;
                continue;
            }

            if ($access === null && $refresh === null) {
                continue;
            }

            if (!$dryRun) {
                if ($access !== null) {
                    $account->setAccessTokenEncrypted($access);
                }
                if ($refresh !== null) {
                    $account->setRefreshTokenEncrypted($refresh);
                }
                $account->touchUpdatedAt();
            }
            ++$rotated;
        }

        if (!$dryRun && $rotated > 0) {
            $this->em->flush();
        }

        return ['rotated' => $rotated, 'failed' => $failed, 'remaining' => $this->remaining()];
    }

    /**
     * Nombre de comptes restant à rechiffrer. C'est le seul critère d'arrêt légitime : tant qu'il
     * n'est pas nul, retirer l'ancienne clé perdrait des jetons.
     */
    public function remaining(): int
    {
        $prefix = $this->cipher->currentVersionPrefix();

        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(SocialAccount::class, 'a')
            ->where('a.accessTokenEncrypted IS NOT NULL AND a.accessTokenEncrypted NOT LIKE :prefix')
            ->orWhere('a.refreshTokenEncrypted IS NOT NULL AND a.refreshTokenEncrypted NOT LIKE :prefix')
            ->setParameter('prefix', $prefix . '%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function reencrypted(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->cipher->reencrypt($value);
    }

    /**
     * @return iterable<string>
     */
    private function allStoredValues(): iterable
    {
        /** @var iterable<array{accessTokenEncrypted: ?string, refreshTokenEncrypted: ?string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('a.accessTokenEncrypted', 'a.refreshTokenEncrypted')
            ->from(SocialAccount::class, 'a')
            ->getQuery()
            ->toIterable();

        foreach ($rows as $row) {
            foreach ([$row['accessTokenEncrypted'] ?? null, $row['refreshTokenEncrypted'] ?? null] as $value) {
                if (is_string($value) && $value !== '') {
                    yield $value;
                }
            }
        }
    }
}
