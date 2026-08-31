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
                if ($contenu !== false && str_contains($this->sansCommentaires($contenu), 'App\\Reservation')) {
                    $violations[] = $fichier->getPathname();
                }
            }
        }

        self::assertSame([], $violations, 'Aucun fichier App\\Piscine|Vente|Offre|Crm|Acces ne doit référencer App\\Reservation (réutilisation via ports, aucune modification de ces modules).');
    }

    /**
     * Le code du fichier, commentaires retires.
     *
     * ⚠ CE CONTROLE LISAIT AUSSI LA PROSE. `str_contains` sur le fichier brut ne distingue pas une
     * dependance d'une phrase : deux fichiers de Piscine tombaient dessus, et tous deux nomment
     * `App\\Reservation` POUR DIRE QU'ILS N'EN DEPENDENT PAS -- l'un explique que declarer le lien
     * serait faux, l'autre attribue un processeur dont il a repris les enseignements.
     *
     * Un controle qui interdit de nommer un module en prose interdit d'ecrire pourquoi on ne s'y
     * lie pas. C'est le commentaire le plus utile du fichier qu'il fait tomber.
     *
     * On passe par le tokenizer de PHP et non par une expression reguliere : `//` dans une chaine,
     * `/*` dans une URL, un antislash echappe -- autant de facons de se tromper sur ce qui est un
     * commentaire. Le tokenizer, lui, le sait.
     */
    private function sansCommentaires(string $contenu): string
    {
        $code = '';

        foreach (token_get_all($contenu) as $jeton) {
            if (\is_array($jeton) && \in_array($jeton[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= \is_array($jeton) ? $jeton[1] : $jeton;
        }

        return $code;
    }

}
