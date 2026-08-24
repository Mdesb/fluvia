<?php

declare(strict_types=1);

namespace App\Tests\RevenueRecovery\Unit;

use PHPUnit\Framework\TestCase;

/**
 * RG-RR-06 (invariant central, plan-revenue-recovery.md §0.2) : aucune classe `App\RevenueRecovery\*`
 * n'importe `App\Securite\Entity\DroitAcces` ni un service de propagation d'accès
 * (`App\Recouvrement\Service\PropagationAccesHandler` ou équivalent) — `RevenueRecovery` n'écrit jamais
 * sur l'accès, invariant d'exclusivité de `App\Recouvrement` (§0.1 de la spec).
 *
 * Analyse statique légère : balaie tous les fichiers PHP sous `src/RevenueRecovery` et échoue si l'un
 * d'eux référence les symboles interdits **dans le code** (via `use`, un FQCN inline, un type). Les
 * commentaires et docblocks sont **ignorés** : ils mentionnent légitimement `DroitAcces` pour *documenter*
 * l'invariant (« ne touche jamais DroitAcces »), sans créer aucune dépendance — l'invariant porte sur le
 * code, pas sur la prose. Le tri se fait par tokens PHP (T_COMMENT/T_DOC_COMMENT écartés).
 */
final class RevenueRecoveryAccessInvariantTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_SYMBOLS = [
        'App\\Securite\\Entity\\DroitAcces',
        'DroitAcces',
        'PropagationAccesHandler',
    ];

    public function testAucuneClasseNimportDroitAccesOuPropagationAcces(): void
    {
        $racine = \dirname(__DIR__, 3) . '/src/RevenueRecovery';
        self::assertDirectoryExists($racine, 'Le module App\\RevenueRecovery doit exister sous app/src/RevenueRecovery.');

        $fichiersPhp = $this->fichiersPhp($racine);
        self::assertNotEmpty($fichiersPhp, 'Aucun fichier PHP trouvé sous src/RevenueRecovery — le balayage RG-RR-06 serait vide de sens.');

        foreach ($fichiersPhp as $fichier) {
            $contenu = $this->codeSansCommentaires((string) file_get_contents($fichier));
            foreach (self::FORBIDDEN_SYMBOLS as $symbole) {
                self::assertStringNotContainsString(
                    $symbole,
                    $contenu,
                    sprintf(
                        'RG-RR-06 violé : « %s » réfère au symbole interdit « %s » — App\\RevenueRecovery '
                        . 'n\'écrit jamais sur App\\Securite\\Entity\\DroitAcces ni sur un service de '
                        . 'propagation d\'accès (invariant central, spec §0.1).',
                        $fichier,
                        $symbole,
                    ),
                );
            }
        }
    }

    /**
     * Reconstruit le code d'un fichier PHP en écartant commentaires et docblocks (T_COMMENT,
     * T_DOC_COMMENT) : l'invariant RG-RR-06 porte sur les dépendances de code, pas sur la prose qui
     * documente précisément « ne touche jamais DroitAcces ».
     */
    private function codeSansCommentaires(string $contenu): string
    {
        $code = '';
        foreach (token_get_all($contenu) as $token) {
            if (\is_array($token)) {
                if (\T_COMMENT === $token[0] || \T_DOC_COMMENT === $token[0]) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    /** @return list<string> */
    private function fichiersPhp(string $racine): array
    {
        $fichiers = [];
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterateur as $fichier) {
            \assert($fichier instanceof \SplFileInfo);
            if ('php' === $fichier->getExtension()) {
                $fichiers[] = $fichier->getPathname();
            }
        }

        return $fichiers;
    }
}
