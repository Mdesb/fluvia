<?php

declare(strict_types=1);

namespace App\Tests\Stay\Unit;

use ApiPlatform\Metadata\ApiResource;
use App\Organisation\Entity\Etablissement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * D41 — **l'établissement d'une entité n'est jamais écrivable depuis l'API.**
 *
 * L'intégrateur a relevé 35 entités qui laissaient modifier leur `etablissement` par une charge utile.
 * La conséquence n'est pas un champ de trop : c'est un déplacement de périmètre offert à l'appelant.
 * Un garde global les refuse désormais — mais le garde protège, la conception doit aussi être juste,
 * et un champ qu'il faut garder n'aurait jamais dû être ouvert.
 *
 * **Ce test couvre tout le périmètre de `claude-F` après D48**, `Boutique`, `Stock` et `Caution`
 * compris : ils sont arrivés le 25/08 avec leur dette, et un test qui ne regarderait que les modules
 * que j'ai écrits moi-même donnerait une fausse assurance sur ceux dont j'ai hérité.
 *
 * **Où il vit.** Cette garde n'appartient à aucun module en particulier — sa place serait
 * `tests/Platform`, qui est le périmètre de l'intégrateur. Elle reste donc ici, dans un répertoire que
 * je possède, et je le signale plutôt que d'écrire hors de chez moi.
 */
final class PerimetreNonEcrivableTest extends TestCase
{
    /**
     * Les modules du périmètre de `claude-F` (D48).
     *
     * `Boutique`, `Stock` et `Caution` vont avec le séjour : un client qui séjourne consomme à la
     * boutique, laisse une caution, et ce qu'il consomme sort d'un stock.
     *
     * @var list<string>
     */
    private const MODULES = ['Stay', 'Lodging', 'Dining', 'Boutique', 'Stock', 'Caution'];

    /** Modules que j'ai écrits, seuls tenus à n'avoir aucun contexte de désérialisation. */
    private const MODULES_A_MOI = ['Stay', 'Lodging', 'Dining'];

    /** @return list<class-string> */
    private static function entites(): array
    {
        $classes = [];
        foreach (self::MODULES as $module) {
            foreach (glob(__DIR__ . '/../../../src/' . $module . '/Entity/*.php') ?: [] as $fichier) {
                $classe = sprintf('App\%s\Entity\%s', $module, basename($fichier, '.php'));
                if (class_exists($classe)) {
                    $classes[] = $classe;
                }
            }
        }

        self::assertNotEmpty($classes, 'Aucune entité trouvée : la découverte est cassée.');

        return $classes;
    }

    public function testLaDecouverteCouvreBienToutLePerimetre(): void
    {
        // Garde-fou du garde-fou : si la découverte cassait, le test suivant passerait au vert sur un
        // tableau vide et n'assurerait plus rien. Cinq modules, donc au moins cinq entités.
        self::assertGreaterThanOrEqual(5, \count(self::entites()));
    }

    public function testAucunEtablissementNestExposeEnEcriture(): void
    {
        $fautifs = [];

        foreach (self::entites() as $classe) {
            foreach ((new \ReflectionClass($classe))->getProperties() as $propriete) {
                $type = $propriete->getType();
                if (!$type instanceof \ReflectionNamedType || Etablissement::class !== $type->getName()) {
                    continue;
                }

                foreach ($propriete->getAttributes(Groups::class) as $attribut) {
                    /** @var list<string>|string $groupes */
                    $groupes = $attribut->getArguments()[0] ?? [];
                    foreach ((array) $groupes as $groupe) {
                        if (str_contains((string) $groupe, 'write')) {
                            $fautifs[] = sprintf('%s::$%s (groupe « %s »)', $classe, $propriete->getName(), $groupe);
                        }
                    }
                }
            }
        }

        // Tous listés d'un coup : corriger un défaut de cette famille à la fois, en relançant entre
        // chaque, fait perdre de vue combien il en reste.
        self::assertSame([], $fautifs, sprintf(
            "D41 — %d champ(s) laissent l'appelant choisir son périmètre :\n  - %s",
            \count($fautifs),
            implode("\n  - ", $fautifs),
        ));
    }

    public function testAucuneOperationNeDeserialiseVersUneEntiteDeMesModules(): void
    {
        foreach (self::entites() as $classe) {
            $mien = false;
            foreach (self::MODULES_A_MOI as $module) {
                $mien = $mien || str_starts_with($classe, sprintf('App\%s\Entity', $module));
            }
            if (!$mien) {
                // `Boutique`, `Stock` et `Caution` sont antérieurs et exposent légitimement des
                // contextes d'écriture sur d'autres champs. Ce qui vaut pour eux, c'est le test
                // précédent : l'établissement, lui, n'est jamais écrivable.
                continue;
            }

            foreach ((new \ReflectionClass($classe))->getAttributes(ApiResource::class) as $attribut) {
                $arguments = $attribut->getArguments();

                self::assertArrayNotHasKey('denormalizationContext', $arguments, sprintf(
                    'D41 : %s déclare un contexte d\'écriture — ses champs exposés doivent alors être audités un à un.',
                    $classe,
                ));

                foreach ($arguments['operations'] ?? [] as $operation) {
                    self::assertFalse(
                        $operation->getInput() ?? false,
                        sprintf('D41 : une opération de %s désérialise vers l\'entité.', $classe),
                    );
                }
            }
        }
    }
}
