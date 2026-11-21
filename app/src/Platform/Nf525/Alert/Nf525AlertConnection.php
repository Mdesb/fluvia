<?php

declare(strict_types=1);

namespace App\Platform\Nf525\Alert;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SECONDE CONNEXION de l'alerte NF525 : l'alerte doit survivre à l'annulation de la vente qui l'a causée
 * (G-2), donc s'écrire sur une session MariaDB distincte de celle de l'appelant (plan, D-A).
 *
 * ── LES PIÈGES ──────────────────────────────────────────────────────────────────────────────────
 *
 * - `getParams()` est `@internal` en DBAL 4.4.4 : son usage est CONFINÉ ICI, et un test sentinelle
 *   (`Nf525AlertStoreTest`) casse si son contenu change. Il rend le `dbname` déjà suffixé (base de test) :
 *   ne JAMAIS repasser ces paramètres par `ConnectionFactory::createConnection()` du bundle (suffixe doublé).
 * - Le bundle ne ferme jamais cette connexion : `close()` en `finally`, toujours. Jamais partagée.
 * - `SET SESSION innodb_lock_wait_timeout` : borne le verrou croisé (la transaction de l'appelant tient la
 *   ligne d'un établissement que l'alerte veut lier). Après une erreur 1205 la transaction reste OUVERTE
 *   (`innodb_rollback_on_timeout = OFF`) : `rollBack()` explicite avant `close()`.
 * - Une erreur à l'OUVERTURE (hôte injoignable, mot de passe refusé) est relancée en `\RuntimeException`
 *   générique, SANS `previous` : la trace d'une exception du pilote peut porter les paramètres de connexion,
 *   donc le mot de passe. Seuls la classe, le code et le message d'origine sont journalisés (le message du
 *   serveur dit « using password: YES », jamais le mot de passe).
 * - BORNE DE DURÉE : le délai de session vaut PAR INSTRUCTION ; l'INSERT, l'UPDATE et chaque liaison peuvent
 *   donc s'additionner. `$work` reçoit un garde (`$budget`) qui lève et annule si le temps écoulé dépasse
 *   le délai ; le Store l'appelle avant chaque liaison. CUMUL RÉSIDUEL : l'INSERT, l'UPDATE et la liaison en
 *   cours au moment du dépassement peuvent chacun attendre un délai plein (au pire ~3 × délai, pas n × délai).
 */
final class Nf525AlertConnection
{
    public const DEFAULT_LOCK_WAIT_SECONDS = 3;

    /** @var \Closure(Connection): Connection */
    private readonly \Closure $factory;
    private readonly LoggerInterface $logger;

    /**
     * @param (callable(Connection): Connection)|null $factory remplace la création (tests) ; par défaut `DriverManager`
     */
    public function __construct(
        private readonly Connection $main,
        private readonly int $lockWaitSeconds = self::DEFAULT_LOCK_WAIT_SECONDS,
        ?callable $factory = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->factory = $factory !== null
            ? \Closure::fromCallable($factory)
            : static fn (Connection $main): Connection => DriverManager::getConnection($main->getParams(), $main->getConfiguration());
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Exécute `$work` dans UNE transaction courte de la seconde connexion : validée si `$work` rend, annulée
     * explicitement sinon (l'exception est relancée telle quelle).
     *
     * @template T
     *
     * @param callable(Connection, \Closure(): void): T $work reçoit la connexion et le garde de durée
     *
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        $started = hrtime(true);
        $budget = function () use ($started): void {
            if ((hrtime(true) - $started) / 1e9 > $this->lockWaitSeconds) {
                throw new \RuntimeException('Alerte NF525 : durée maximale d\'écriture dépassée.');
            }
        };

        $connection = $this->open();
        try {
            $connection->beginTransaction();
            try {
                $result = $work($connection, $budget);
                $connection->commit();

                return $result;
            } catch (\Throwable $e) {
                try {
                    if ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                } catch (\Throwable) {
                    // La session est fermée juste après ; l'erreur d'origine prime.
                }
                throw $e;
            }
        } finally {
            $connection->close();
        }
    }

    private function open(): Connection
    {
        $connection = null;
        try {
            $connection = ($this->factory)($this->main);
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = ' . max(0, $this->lockWaitSeconds));

            return $connection;
        } catch (\Throwable $e) {
            $this->logger->critical('Alerte NF525 : ouverture de la seconde connexion impossible.', [
                'exception_class' => $e::class,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
            try {
                $connection?->close();
            } catch (\Throwable) {
            }

            // Volontairement sans `previous` ni paramètre : aucune trace ne doit porter le mot de passe.
            throw new \RuntimeException('Alerte NF525 : seconde connexion indisponible.');
        }
    }
}
