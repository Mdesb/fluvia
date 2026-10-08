<?php

declare(strict_types=1);

namespace App\Tests\Vente\Support;

/**
 * Une AUTRE requête, dans un autre processus PHP, à travers le noyau complet : ce que ferait une
 * seconde requête php-fpm (`settle-in-other-process.php`). Elle s'arrête au point posé par
 * `SettlementBarrier` (terminal, débit du porte-monnaie) jusqu'à ce que le test la libère.
 */
trait OtherProcess
{
    /**
     * @param string               $cible la vente à régler, ou le chemin d'une autre écriture (« /api/… »)
     * @param array<string, mixed> $corps
     *
     * @return array{0: resource, 1: string}
     */
    private function settleInOtherProcess(string $cible, string $jeton, string $idA, array $corps, string $tpe = ''): array
    {
        $dossier = sys_get_temp_dir() . '/settle-' . bin2hex(random_bytes(6));
        mkdir($dossier);
        // Le journal va dans un FICHIER : un tuyau plein que personne ne lit bloquerait l'enfant.
        $processus = proc_open(
            [\PHP_BINARY, '-d', 'memory_limit=1G', __DIR__ . '/settle-in-other-process.php', $dossier, $cible, $jeton, $idA, (string) json_encode($corps), $tpe],
            [1 => ['file', $dossier . '/out.log', 'w'], 2 => ['file', $dossier . '/err.log', 'w']],
            $tuyaux,
        );
        self::assertIsResource($processus, 'L\'autre processus n\'a pas démarré.');

        return [$processus, $dossier];
    }

    /** @param array{0: resource, 1: string} $autre */
    private function waitUntilHeld(array $autre, string $etape): void
    {
        [$processus, $dossier] = $autre;
        $limite = microtime(true) + 60;
        while (!is_file($dossier . '/' . $etape . '.reached')) {
            if (!proc_get_status($processus)['running'] || microtime(true) > $limite) {
                touch($dossier . '/release');
                proc_close($processus);
                self::fail(sprintf(
                    'L\'autre processus n\'a pas atteint « %s » : %s %s',
                    $etape,
                    (string) @file_get_contents($dossier . '/response.json'),
                    substr((string) @file_get_contents($dossier . '/err.log'), -3000),
                ));
            }
            usleep(20_000);
        }
    }

    /**
     * Libère l'autre processus dans `$secondes`, depuis un troisième : pour un test dont la requête
     * ATTEND l'autre (un verrou), et ne pourrait donc pas le libérer elle-même.
     *
     * @param array{0: resource, 1: string} $autre
     *
     * @return resource
     */
    private function releaseLater(array $autre, float $secondes)
    {
        $minuterie = proc_open([\PHP_BINARY, '-r', 'usleep((int) $argv[1]); touch($argv[2]);', (string) (int) ($secondes * 1_000_000), $autre[1] . '/release'], [], $tuyaux);
        self::assertIsResource($minuterie, 'La minuterie n\'a pas démarré.');

        return $minuterie;
    }

    /**
     * @param array{0: resource, 1: string} $autre
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function release(array $autre): array
    {
        [$processus, $dossier] = $autre;
        touch($dossier . '/release');
        $code = proc_close($processus);
        $erreurs = substr((string) @file_get_contents($dossier . '/err.log'), -3000);
        $reponse = json_decode((string) @file_get_contents($dossier . '/response.json'), true);
        array_map('unlink', (array) glob($dossier . '/*'));
        rmdir($dossier);
        self::assertSame(0, $code, 'L\'autre processus a échoué : ' . $erreurs);
        self::assertIsArray($reponse, 'L\'autre processus n\'a rien répondu : ' . $erreurs);

        return ['status' => (int) $reponse['status'], 'body' => (array) json_decode((string) $reponse['body'], true)];
    }
}
