<?php

declare(strict_types=1);

namespace App\Tests\Compta\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Test d'architecture (§0/§12 du plan) : aucune classe du domaine Compta ne doit tester le
 * discriminant `ProfilExploitant::type`/`getType()` directement — seuls `RegimeComptableResolver`
 * (résolution) et `SelecteurReferentielPublic`/`ParametresRegime::defautPour` (défauts paramétrables,
 * pas une branche sur le discriminant *exploité* par le métier) y sont autorisés.
 */
final class ArchitectureRegimeTest extends TestCase
{
    private const AUTORISES = [
        'RegimeComptableResolver.php',
        'ParametresRegime.php', // defautPour() : simple table de défaut, pas une branche métier.
    ];

    public function testAucuneClasseHorsResolverNeTesteLeDiscriminant(): void
    {
        $racine = \dirname(__DIR__, 3) . '/src/Compta';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS));

        $violations = [];
        foreach ($iterator as $fichier) {
            if (!$fichier instanceof \SplFileInfo || $fichier->getExtension() !== 'php') {
                continue;
            }
            if (\in_array($fichier->getFilename(), self::AUTORISES, true)) {
                continue;
            }

            $contenu = (string) file_get_contents($fichier->getPathname());
            // Cible spécifiquement le discriminant ProfilExploitant::type (TypeExploitant), pas tout
            // getType() (d'autres entités du module — MouvementPca, etc. — ont aussi un getType()).
            if (preg_match('/->getType\(\)\s*(===|==|!==|!=)\s*(TypeExploitant|\\\\App\\\\Compta\\\\Enum\\\\TypeExploitant)::/', $contenu)
                || preg_match('/\bmatch\s*\(\s*\$profil->getType\(\)/', $contenu)
            ) {
                $violations[] = $fichier->getPathname();
            }
        }

        self::assertSame([], $violations, 'Discriminant ProfilExploitant::type lu hors du Resolver : ' . implode(', ', $violations));
    }
}
