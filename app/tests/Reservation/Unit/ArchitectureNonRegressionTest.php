<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Non-régression architecturale (DoD constitution §8) : `App\Reservation` est additif — aucun fichier
 * `App\Piscine`/`App\Vente`/`App\Offre`/`App\Crm`/`App\Acces` (modules réutilisés via ports/références
 * logiques, jamais modifiés par ce lot) ne référence `App\Reservation` (patron
 * `App\Tests\Sport\Unit\ArchitectureNonRegressionTest`).
 */
final class ArchitectureNonRegressionTest extends TestCase
{
    public function testAucunModuleSocleNeReferenceAppReservation(): void
    {
        $racine = \dirname(__DIR__, 3) . '/src';
        $modulesProteges = ['Piscine', 'Vente', 'Offre', 'Crm', 'Acces'];

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
                if ($contenu !== false && str_contains($contenu, 'App\\Reservation')) {
                    $violations[] = $fichier->getPathname();
                }
            }
        }

        self::assertSame([], $violations, 'Aucun fichier App\\Piscine|Vente|Offre|Crm|Acces ne doit référencer App\\Reservation (réutilisation via ports, aucune modification de ces modules).');
    }
}
