<?php

declare(strict_types=1);

namespace App\Tests\Stay\Unit;

use ApiPlatform\Metadata\ApiResource;
use App\Stay\Doctrine\PerimetreStayExtension;
use PHPUnit\Framework\TestCase;

/**
 * **Le test qui empêche la faille silencieuse.**
 *
 * `PerimetreStayExtension::CHAINES` doit lister toute entité du module exposée en `ApiResource`. En
 * oublier une ne casse rien de visible : la ressource répond, simplement sans filtre d'établissement,
 * c'est-à-dire en IDOR. Aucun test fonctionnel du module ne s'en apercevrait — il faudrait un test
 * écrit exprès avec deux établissements.
 *
 * Ici, l'oubli devient rouge à la seconde où quelqu'un ajoute `#[ApiResource]` à une entité sans
 * toucher à la table de cloisonnement. C'est le même esprit que le garde-fou « couverture de
 * périmètre », mais à l'échelle du module, et sans attendre la poussée.
 */
final class PerimetreStayExtensionTest extends TestCase
{
    /** @return list<class-string> */
    private static function entitesExposees(): array
    {
        $exposees = [];

        foreach (glob(__DIR__ . '/../../../src/Stay/Entity/*.php') ?: [] as $fichier) {
            $classe = 'App\\Stay\\Entity\\' . basename($fichier, '.php');
            if (!class_exists($classe)) {
                continue;
            }

            if ([] !== (new \ReflectionClass($classe))->getAttributes(ApiResource::class)) {
                $exposees[] = $classe;
            }
        }

        return $exposees;
    }

    public function testLeModuleExposeBienQuelqueChose(): void
    {
        // Garde-fou du garde-fou : si la découverte par réflexion cassait, le test suivant passerait
        // sur un tableau vide et ne prouverait plus rien.
        self::assertNotEmpty(self::entitesExposees(), 'Aucune entité exposée trouvée : la découverte est cassée.');
    }

    public function testTouteEntiteExposeeEstCloisonnee(): void
    {
        foreach (self::entitesExposees() as $classe) {
            self::assertArrayHasKey(
                $classe,
                PerimetreStayExtension::CHAINES,
                sprintf(
                    'L\'entité « %s » est exposée en ApiResource mais absente de PerimetreStayExtension::CHAINES : '
                    . 'sa collection répondrait sans filtre d\'établissement (IDOR).',
                    $classe,
                ),
            );
        }
    }

    public function testAucuneEntreeMorteDansLaTableDeCloisonnement(): void
    {
        // L'inverse compte aussi : une entrée qui ne correspond plus à rien laisse croire à une
        // protection qui ne s'applique nulle part.
        foreach (array_keys(PerimetreStayExtension::CHAINES) as $classe) {
            self::assertContains($classe, self::entitesExposees(), sprintf(
                'PerimetreStayExtension::CHAINES déclare « %s », qui n\'est plus exposée.',
                $classe,
            ));
        }
    }
}
