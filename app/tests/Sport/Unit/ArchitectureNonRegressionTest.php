<?php

declare(strict_types=1);

namespace App\Tests\Sport\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Non-régression architecturale (plan-sport.md, tasks-sport.md en-tête) : Sport est additif — aucun
 * fichier `App\Acces\*`/`App\Compta\*`/`App\Offre\*`/`App\Crm\*` ne référence `App\Sport` (aucune
 * dépendance retour vers la verticale, seul `App\Audit\Doctrine\AuditWriteSubscriber` — fichier socle
 * partagé, extension additive documentée — est exempté).
 */
final class ArchitectureNonRegressionTest extends TestCase
{
    public function testAucunModuleSocleNeReferenceAppSport(): void
    {
        $racine = \dirname(__DIR__, 3) . '/src';
        $modulesProteges = ['Acces', 'Compta', 'Offre', 'Crm'];

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
                if ($contenu !== false && str_contains($contenu, 'App\\Sport')) {
                    $violations[] = $fichier->getPathname();
                }
            }
        }

        self::assertSame([], $violations, 'Aucun fichier App\\Acces|Compta|Offre|Crm ne doit référencer App\\Sport (additivité, plan §0).');
    }

    public function testAuditWriteSubscriberEstLaSeuleExtensionAdditiveDuSocle(): void
    {
        $fichier = \dirname(__DIR__, 3) . '/src/Audit/Doctrine/AuditWriteSubscriber.php';
        self::assertFileExists($fichier);
        $contenu = file_get_contents($fichier);
        // ⚠ LE CHEMIN A CHANGÉ AU LOT 1, PAS L'INTENTION. L'entité a quitté `App\Sport` ; ce que ce
        // test garde reste le même — l'abonnement doit être surveillé par l'audit, et il l'est par
        // une liste blanche où un nom absent ne produit AUCUNE erreur, juste un silence.
        self::assertStringContainsString('App\Membership\Entity\Membership', (string) $contenu);
    }
}
