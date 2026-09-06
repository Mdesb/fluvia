<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Non-régression architecturale (DoD constitution §8, mission Patinoire) : `App\Patinoire` est additif
 * — aucun fichier `App\Reservation`/`App\Vente`/`App\Offre`/`App\Crm`/`App\Acces`/`App\Piscine`
 * (modules réutilisés via ports/références logiques, jamais modifiés par ce lot) ne référence
 * `App\Patinoire` (patron `App\Tests\Padel\Unit\ArchitectureNonRegressionTest`).
 */
final class ArchitectureNonRegressionTest extends TestCase
{
    public function testAucunModuleSocleNeReferenceAppPatinoire(): void
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
                if ($contenu !== false && self::referenceReellement($contenu)) {
                    $violations[] = $fichier->getPathname();
                }
            }
        }

        self::assertSame([], $violations, 'Aucun fichier App\\Reservation|Vente|Offre|Crm|Acces|Piscine ne doit référencer App\\Patinoire (réutilisation via ports/références logiques, aucune modification de ces modules).');
    }

    /**
     * CE FICHIER RÉFÉRENCE-T-IL VRAIMENT `App\Patinoire`, OU SE CONTENTE-T-IL D'EN PARLER ?
     *
     * ⚠ CE TÉMOIN A ÉTÉ ROUGE SUR `main` DU 05/09 AU 06/09, POUR UN COMMENTAIRE.
     * `QualificationEncadrant.php:37` citait Patinoire comme précédent de conception — « même patron
     * que `App\Reservation` et `App\Patinoire` » — exactement le genre de renvoi qu'on veut
     * encourager entre modules. `str_contains()` le comptait comme une violation.
     *
     * Le message de ce test dit « ne doit RÉFÉRENCER » ; la mesure disait « ne doit MENTIONNER ».
     * Un écart pareil coûte deux fois : il rend rouge un dépôt sain, et il apprend à passer outre —
     * après quoi la vraie violation passera avec les autres.
     *
     * `token_get_all()` est l'analyseur de PHP lui-même : il sait ce qu'est un commentaire. Une
     * référence réelle reste vue, qu'elle soit un `use`, un type, ou un nom de classe écrit en
     * chaîne — seule la prose est épargnée.
     */
    private static function referenceReellement(string $contenu): bool
    {
        foreach (token_get_all($contenu) as $jeton) {
            if (\is_array($jeton) && \in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $texte = \is_array($jeton) ? $jeton[1] : $jeton;
            if (str_contains($texte, 'App\\Patinoire') || str_contains($texte, 'Patinoire\\')) {
                return true;
            }
        }

        return false;
    }
}
