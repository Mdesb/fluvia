<?php

declare(strict_types=1);

namespace App\Tests\Padel\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Non-régression architecturale (DoD constitution §8, mission Padel) : `App\Padel` est additif —
 * aucun fichier `App\Reservation`/`App\Vente`/`App\Offre`/`App\Crm`/`App\Acces`/`App\Piscine`
 * (modules réutilisés via ports/références logiques, jamais modifiés par ce lot) ne référence
 * `App\Padel` (patron `App\Tests\Reservation\Unit\ArchitectureNonRegressionTest`).
 */
final class ArchitectureNonRegressionTest extends TestCase
{
    public function testAucunModuleSocleNeReferenceAppPadel(): void
    {
        $racine = \dirname(__DIR__, 3) . '/src';
        $modulesProteges = ['Reservation', 'Vente', 'Offre', 'Crm', 'Acces', 'Piscine'];

        $violations = [];
        foreach ($modulesProteges as $module) {
            $dossier = $racine . '/' . $module;
            if (!is_dir($dossier)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fichier) {
                if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
                    continue;
                }
                $contenu = file_get_contents($fichier->getPathname());
                if ($contenu !== false && str_contains($contenu, 'App\\Padel')) {
                    $violations[] = $fichier->getPathname();
                }
            }
        }

        self::assertSame([], $violations, 'Aucun fichier App\\Reservation|Vente|Offre|Crm|Acces|Piscine ne doit référencer App\\Padel (réutilisation via ports/références logiques, aucune modification de ces modules).');
    }
}
