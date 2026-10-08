<?php

declare(strict_types=1);

namespace App\Tests\Vente\Support;

use Doctrine\DBAL\Connection;

/**
 * Une AUTRE connexion, au milieu de sa transaction : elle verrouille une ligne (`FOR UPDATE`), écrit,
 * rend la main, attend deux secondes, puis valide. Le test mesure que sa propre requête ATTEND ce
 * verrou, puis voit ce que l'autre a écrit (patron de `WalletPartialRefundTest`).
 */
trait RowHolder
{
    /**
     * @param list<string>       $sql       la première instruction verrouille, les suivantes écrivent
     * @param list<list<string>> $arguments les arguments positionnels de chacune
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function holdRow(array $sql, array $arguments): array
    {
        /** @var Connection $connexion */
        $connexion = static::getContainer()->get('doctrine')->getConnection();
        $p = $connexion->getParams();
        $code = <<<'PHP'
            [$dsn, $user, $pass, $json] = array_slice($argv, 1);
            [$sql, $arguments] = json_decode($json, true);
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->beginTransaction();
            foreach ($sql as $i => $instruction) {
                $pdo->prepare($instruction)->execute($arguments[$i]);
            }
            echo "LOCKED\n";
            usleep(2000000);
            $pdo->commit();
            echo "COMMITTED\n";
            PHP;
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $p['host'] ?? 'localhost', $p['port'] ?? 3306, $p['dbname'] ?? '');
        $processus = proc_open(
            [\PHP_BINARY, '-r', $code, $dsn, (string) ($p['user'] ?? ''), (string) ($p['password'] ?? ''), (string) json_encode([$sql, $arguments])],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tuyaux,
        );
        self::assertIsResource($processus, 'La connexion concurrente n\'a pas démarré.');
        if (trim((string) fgets($tuyaux[1])) !== 'LOCKED') {
            $erreur = stream_get_contents($tuyaux[2]);
            proc_close($processus);
            self::fail('La connexion concurrente ne tient pas la ligne : ' . $erreur);
        }

        return [$processus, $tuyaux];
    }

    /** @param array{0: resource, 1: array<int, resource>} $autre */
    private function releaseRow(array $autre): void
    {
        [$processus, $tuyaux] = $autre;
        $sortie = stream_get_contents($tuyaux[1]);
        $erreur = stream_get_contents($tuyaux[2]);
        self::assertSame(0, proc_close($processus), 'La connexion concurrente a échoué : ' . $erreur);
        self::assertStringContainsString('COMMITTED', (string) $sortie);
    }
}
