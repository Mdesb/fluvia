<?php

declare(strict_types=1);

namespace App\Tests\Vente\Support;

/**
 * Arrête un AUTRE processus au milieu d'un règlement, jusqu'à ce que le test le libère.
 *
 * PHPUnit ne lance pas deux requêtes en même temps : la concurrence se joue avec un second processus
 * (`settle-in-other-process.php`), qui pose `SETTLEMENT_BARRIER` sur un dossier. Arrivé au point
 * d'arrêt, il y écrit `<étape>.reached`, puis attend le fichier `release`. Sans la variable — dans le
 * processus de PHPUnit lui-même —, rien n'attend : les décorateurs laissent passer.
 */
final class SettlementBarrier
{
    public const ENV = 'SETTLEMENT_BARRIER';

    private const MAX_WAIT_SECONDS = 30;

    public static function hold(string $stage): void
    {
        $dossier = getenv(self::ENV);
        if (!\is_string($dossier) || $dossier === '') {
            return;
        }

        touch($dossier . '/' . $stage . '.reached');
        $limite = microtime(true) + self::MAX_WAIT_SECONDS;
        while (!is_file($dossier . '/release')) {
            if (microtime(true) > $limite) {
                throw new \RuntimeException('Barrière jamais levée : ' . $dossier);
            }
            usleep(10_000);
        }
    }
}
