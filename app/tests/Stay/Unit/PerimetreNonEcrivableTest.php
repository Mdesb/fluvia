<?php

declare(strict_types=1);

namespace App\Tests\Stay\Unit;

use ApiPlatform\Metadata\ApiResource;
use App\Organisation\Entity\Etablissement;
use Symfony\Component\Serializer\Attribute\Groups;

use PHPUnit\Framework\TestCase;

/**
 * D41 — **l'établissement d'une entité ne doit jamais être écrivable depuis l'API.**
 *
 * L'intégrateur a relevé **35 entités** du dépôt qui laissent modifier leur `etablissement` par une
 * charge utile. La conséquence n'est pas un simple champ de trop : c'est un déplacement de périmètre
 * offert à l'appelant. Un séjour qu'on peut réaffecter à un autre établissement emporte avec lui ses
 * lignes déjà encaissées, et contourne d'un `PATCH` tout le cloisonnement construit par-dessus.
 *
 * Ce test **fige** la conformité de `App\Stay` plutôt que de la constater une fois. Aujourd'hui elle
 * tient pour une raison plus forte que « le champ n'est pas dans un groupe d'écriture » : le module
 * n'expose **aucun** groupe d'écriture, et aucune de ses opérations ne désérialise vers l'entité
 * (`input: false`, corps lu dans le processor). Le jour où quelqu'un ajoutera un `PATCH` par commodité,
 * ce test tombera avant la revue.
 */
final class PerimetreNonEcrivableTest extends TestCase
{
    /** @return list<class-string> */
    private static function entites(): array
    {
        $classes = [];
        foreach (glob(__DIR__ . '/../../../src/Stay/Entity/*.php') ?: [] as $fichier) {
            $classe = 'App\\Stay\\Entity\\' . basename($fichier, '.php');
            if (class_exists($classe)) {
                $classes[] = $classe;
            }
        }

        self::assertNotEmpty($classes, 'Aucune entité trouvée : la découverte est cassée.');

        return $classes;
    }

    public function testAucunEtablissementNestExposeEnEcriture(): void
    {
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
                        self::assertStringNotContainsString(
                            'write',
                            (string) $groupe,
                            sprintf(
                                'D41 : %s::$%s expose l\'établissement dans le groupe « %s » — le périmètre deviendrait modifiable par l\'appelant.',
                                $classe,
                                $propriete->getName(),
                                $groupe,
                            ),
                        );
                    }
                }
            }
        }
    }

    public function testAucuneOperationNeDeserialiseVersUneEntite(): void
    {
        foreach (self::entites() as $classe) {
            foreach ((new \ReflectionClass($classe))->getAttributes(ApiResource::class) as $attribut) {
                $arguments = $attribut->getArguments();

                self::assertArrayNotHasKey(
                    'denormalizationContext',
                    $arguments,
                    sprintf('D41 : %s déclare un contexte d\'écriture — les champs exposés doivent alors être audités un à un.', $classe),
                );

                foreach ($arguments['operations'] ?? [] as $operation) {
                    // Toute écriture passe par un processor qui lit le corps lui-même : rien n'est
                    // désérialisé vers l'entité, donc aucun champ ne peut être écrit par surprise.
                    self::assertFalse(
                        $operation->getInput() ?? false,
                        sprintf('D41 : une opération de %s désérialise vers l\'entité.', $classe),
                    );
                }
            }
        }
    }
}
