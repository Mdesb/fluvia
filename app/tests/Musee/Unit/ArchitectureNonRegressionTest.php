<?php

declare(strict_types=1);

namespace App\Tests\Musee\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Non-régression architecturale (DoD constitution §8, mission Musée) : `App\Musee` est additif —
 * aucun fichier `App\Reservation`/`App\Acces`/`App\Vente`/`App\Offre`/`App\Crm` (modules réutilisés
 * via ports/références logiques/listeners additifs, jamais modifiés par ce lot) ne référence
 * `App\Musee` (patron `App\Tests\Padel\Unit\ArchitectureNonRegressionTest`).
 */
final class ArchitectureNonRegressionTest extends TestCase
{
    public function testAucunModuleSocleNeReferenceAppMusee(): void
    {
        $racine = \dirname(__DIR__, 3) . '/src';
        $modulesProteges = ['Reservation', 'Acces', 'Vente', 'Offre', 'Crm'];

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
                if ($contenu !== false && str_contains($contenu, 'App\\Musee')) {
                    $violations[] = $fichier->getPathname();
                }
            }
        }

        self::assertSame([], $violations, 'Aucun fichier App\\Reservation|Acces|Vente|Offre|Crm ne doit référencer App\\Musee (réutilisation via ports/listeners additifs, aucune modification de ces modules).');
    }
}
