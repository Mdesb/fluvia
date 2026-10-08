<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\Vente;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * CE QUE LE LOT 2 SUPPOSE DE MARIADB ET DE DOCTRINE, MESURÉ À DEUX CONNEXIONS RÉELLES.
 *
 * Le plan marquait UNVERIFIED (U-5, U-6) : plusieurs `NULL` dans un index unique ; deux `INSERT`
 * concurrents sur une même clé unique ; `refresh()` qui remplace les collections. Aucune fiche ne
 * décrit le second cas (`docs/references/mariadb-11.4.md`, « À VÉRIFIER ») : il se prouve ici.
 *
 * La tentative tient la vente par `open_sale_id` (unique, nul une fois close) et sa clé par
 * `idempotency_key` (unique). La seconde connexion est un AUTRE processus (PDO), qui garde sa
 * transaction ouverte deux secondes pendant que celle-ci écrit.
 */
final class PaymentAttemptSchemaTest extends VenteApiTestCase
{
    private const HOLD_SECONDS = 2;

    /** Deux tentatives ouvertes ne tiennent pas la même vente ; closes (créneau nul), elles sont admises. */
    public function testOneOpenAttemptPerSaleAndAnyNumberOfClosedOnes(): void
    {
        $vente = $this->sale();
        $this->db()->executeStatement($this->insert(), $this->row($vente, open: true));

        try {
            $this->db()->executeStatement($this->insert(), $this->row($vente, open: true));
            self::fail('Deux tentatives ouvertes sur la même vente.');
        } catch (UniqueConstraintViolationException) {
        }

        $this->db()->executeStatement($this->insert(), $this->row($vente, open: false));
        $this->db()->executeStatement($this->insert(), $this->row($vente, open: false));
        self::assertSame(3, $this->attemptCount($vente), 'Une ouverte, deux closes : plusieurs NULL passent l\'index unique.');
    }

    public function testOneAttemptPerKey(): void
    {
        $vente = $this->sale();
        $ligne = $this->row($vente, open: false);
        $this->db()->executeStatement($this->insert(), $ligne);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->db()->executeStatement($this->insert(), ['id' => $this->hex((string) Uuid::v4())] + $ligne);
    }

    /** Une vente tenue dans une transaction non validée : le nouveau venu ATTEND, puis échoue en doublon. */
    public function testAConcurrentInsertOnAHeldSaleWaitsThenFails(): void
    {
        $vente = $this->sale();
        [$issue, $attente] = $this->whileAnotherConnectionHolds($this->insert(), $this->row($vente, open: true), 'commit', $this->row($vente, open: true));

        self::assertSame('duplicate', $issue);
        self::assertGreaterThan(self::HOLD_SECONDS - 0.5, $attente, 'L\'INSERT attend la fin de l\'autre transaction : il ne voit pas « libre ».');
        self::assertSame(1, $this->attemptCount($vente));
    }

    /** La même chose, quand l'autre transaction est annulée : après l'attente, le créneau est libre et l'INSERT passe. */
    public function testAConcurrentInsertOnAHeldSalePassesIfTheHolderRollsBack(): void
    {
        $vente = $this->sale();
        [$issue, $attente] = $this->whileAnotherConnectionHolds($this->insert(), $this->row($vente, open: true), 'rollback', $this->row($vente, open: true));

        self::assertSame('inserted', $issue);
        self::assertGreaterThan(self::HOLD_SECONDS - 0.5, $attente);
        self::assertSame(1, $this->attemptCount($vente));
    }

    /**
     * Une tentative close DANS une transaction non validée (le cas du coordinateur : règlement écrit et
     * créneau libéré ensemble) : le nouveau venu attend la validation, puis prend le créneau.
     */
    public function testASaleFreedInsideAnOpenTransactionIsTakenOnceCommitted(): void
    {
        $vente = $this->sale();
        $tenante = $this->row($vente, open: true);
        $this->db()->executeStatement($this->insert(), $tenante);

        [$issue, $attente] = $this->whileAnotherConnectionHolds(
            'UPDATE sale_payment_attempt SET open_sale_id = NULL, status = \'accepted\' WHERE id = UNHEX(?)',
            ['id' => $tenante['id']],
            'commit',
            $this->row($vente, open: true),
        );

        self::assertSame('inserted', $issue);
        self::assertGreaterThan(self::HOLD_SECONDS - 0.5, $attente);
    }

    /**
     * La validation verrouille la ligne de la vente (`FOR UPDATE`) avant de chercher une tentative qui
     * la tient (lot 3, D-4) : une tentative qui s'ouvre pendant ce temps ATTEND la fin de la validation
     * — la clé étrangère `sale_id` pose un verrou partagé sur la vente. Sans cette attente, une carte
     * pouvait partir au terminal pendant le scellement d'une vente qui ne l'attendait plus.
     */
    public function testAnAttemptWaitsWhileTheSaleRowIsLockedForUpdate(): void
    {
        $vente = $this->sale();
        [$issue, $attente] = $this->whileAnotherConnectionHolds('SELECT id FROM vente_vente WHERE id = UNHEX(:v) FOR UPDATE', ['v' => $this->hex($vente)], 'commit', $this->row($vente, open: true));

        self::assertSame('inserted', $issue);
        self::assertGreaterThan(self::HOLD_SECONDS - 0.5, $attente, 'L\'ouverture de la tentative attend la fin de la transaction qui tient la vente.');
    }

    /** La même clé, écrite par deux connexions à la fois : la seconde attend, puis échoue en doublon. */
    public function testTheSameKeyFromTwoConnectionsWaitsThenFails(): void
    {
        $vente = $this->sale();
        $ligne = $this->row($vente, open: false);
        [$issue, $attente] = $this->whileAnotherConnectionHolds($this->insert(), $ligne, 'commit', ['id' => $this->hex((string) Uuid::v4())] + $ligne);

        self::assertSame('duplicate', $issue);
        self::assertGreaterThan(self::HOLD_SECONDS - 0.5, $attente);
    }

    /**
     * `refresh()` remplace la collection des règlements : un règlement écrit par une AUTRE connexion
     * après le chargement y apparaît, et le reste dû relu est celui de la base. Les lignes restent.
     */
    public function testRefreshReloadsWhatAnotherConnectionWrote(): void
    {
        $vente = $this->sale();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $entite = $em->find(Vente::class, Uuid::fromString($vente));
        self::assertInstanceOf(Vente::class, $entite);
        self::assertSame([0, 1, '45.00'], [\count($entite->getPaiements()), \count($entite->getLignes()), $entite->getResteAPayer()], 'témoin : collections chargées avant l\'écriture concurrente');

        $autre = DriverManager::getConnection($this->db()->getParams());
        $autre->executeStatement(
            "INSERT INTO vente_paiement (id, vente_id, moyen_code, montant, rendu, differe, date_heure) VALUES (UNHEX(:id), UNHEX(:v), 'especes', '45.00', '0.00', 0, NOW())",
            ['id' => $this->hex((string) Uuid::v4()), 'v' => $this->hex($vente)],
        );
        $autre->executeStatement("UPDATE vente_vente SET reste_apayer = '0.00' WHERE id = UNHEX(:v)", ['v' => $this->hex($vente)]);
        $autre->close();
        self::assertCount(0, $entite->getPaiements(), 'témoin : sans relecture, la mémoire ignore le règlement');

        $em->refresh($entite);
        self::assertSame([1, '0.00'], [\count($entite->getPaiements()), $entite->getResteAPayer()]);

        $em->flush();
        $em->clear();
        self::assertCount(1, $em->find(Vente::class, Uuid::fromString($vente))?->getLignes() ?? [], 'Le remplacement des collections ne supprime aucune ligne.');
    }

    /**
     * Fait tenir `$sql` à un autre processus, dans une transaction ouverte {@see HOLD_SECONDS} s puis
     * validée ou annulée ; pendant ce temps, insère `$nouvelle` ici et mesure l'attente.
     *
     * @param array<string, mixed> $parametres
     * @param array<string, mixed> $nouvelle
     *
     * @return array{0: 'inserted'|'duplicate', 1: float}
     */
    private function whileAnotherConnectionHolds(string $sql, array $parametres, string $issue, array $nouvelle): array
    {
        $p = $this->db()->getParams();
        $code = <<<'PHP'
            [$dsn, $user, $pass, $sql, $params, $issue, $hold] = array_values(array_filter(array_slice($argv, 1), static fn ($a) => $a !== '--'));
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->beginTransaction();
            $pdo->prepare($sql)->execute(array_values(json_decode($params, true)));
            echo "HELD\n";
            usleep((int) $hold * 1000000);
            $issue === 'commit' ? $pdo->commit() : $pdo->rollBack();
            echo "DONE\n";
            PHP;
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $p['host'] ?? 'localhost', $p['port'] ?? 3306, $p['dbname'] ?? '');
        // PDO reçoit les mêmes paramètres, dans l'ordre : chaque `:nom` devient `?`.
        $positionnel = (string) preg_replace('/:\w+/', '?', $sql);
        $processus = proc_open(
            [\PHP_BINARY, '-r', $code, '--', $dsn, (string) ($p['user'] ?? ''), (string) ($p['password'] ?? ''), $positionnel, (string) json_encode($parametres), $issue, (string) self::HOLD_SECONDS],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tuyaux,
        );
        self::assertIsResource($processus);
        $premiere = (string) fgets($tuyaux[1]);
        if (trim($premiere) !== 'HELD') {
            // `php -r` écrit ses erreurs sur la sortie standard : on rend les deux.
            $erreur = $premiere . stream_get_contents($tuyaux[1]) . stream_get_contents($tuyaux[2]);
            proc_close($processus);
            self::fail('L\'autre connexion ne tient pas sa transaction : ' . $erreur);
        }

        $debut = microtime(true);
        try {
            $this->db()->executeStatement($this->insert(), $nouvelle);
            $resultat = 'inserted';
        } catch (UniqueConstraintViolationException) {
            $resultat = 'duplicate';
        }
        $attente = microtime(true) - $debut;

        $sortie = stream_get_contents($tuyaux[1]);
        $erreur = stream_get_contents($tuyaux[2]);
        self::assertSame(0, proc_close($processus), 'L\'autre connexion a échoué : ' . $erreur);
        self::assertStringContainsString('DONE', (string) $sortie);

        return [$resultat, $attente];
    }

    private function insert(): string
    {
        return 'INSERT INTO sale_payment_attempt (id, sale_id, open_sale_id, idempotency_key, payment_method_code, requested_amount, amount, uses_terminal, status, started_at) '
            . 'VALUES (UNHEX(:id), UNHEX(:sale), UNHEX(:open), UNHEX(:k), :m, NULL, :a, 0, :s, :d)';
    }

    /** @return array<string, mixed> */
    private function row(string $vente, bool $open): array
    {
        return [
            'id' => $this->hex((string) Uuid::v4()),
            'sale' => $this->hex($vente),
            'open' => $open ? $this->hex($vente) : null,
            'k' => $this->hex((string) Uuid::v4()),
            'm' => 'especes',
            'a' => '45.00',
            's' => $open ? 'pending' : 'failed',
            'd' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }

    /** Une vente de 45,00 en cours, avec sa ligne, par l'API. */
    private function sale(): string
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + ['json' => [
            'produit' => '/api/produits/' . $this->idProduit(\App\Offre\DataFixtures\OffreFixtures::PRODUIT_CARTE),
            'typeTarif' => '/api/type_tarifs/' . $this->idTarif(\App\Offre\DataFixtures\OffreFixtures::TARIF_PLEIN),
            'quantite' => 1,
        ]]);
        self::assertResponseIsSuccessful();

        return $vente['id'];
    }

    private function attemptCount(string $vente): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM sale_payment_attempt WHERE sale_id = UNHEX(:v)', ['v' => $this->hex($vente)]);
    }

    private function db(): Connection
    {
        /** @var Connection $connexion */
        $connexion = static::getContainer()->get('doctrine')->getConnection();

        return $connexion;
    }

    private function hex(string $uuid): string
    {
        return str_replace('-', '', $uuid);
    }
}
