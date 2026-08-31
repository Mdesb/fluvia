<?php

declare(strict_types=1);

namespace App\Platform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Rend le commit tel que **ce processus PHP** l'a chargé (voir `LoadedBuild`).
 *
 * Le déploiement écrit `var/build-version.php` puis redémarre FPM. Ce fichier est chargé comme
 * n'importe quel autre fichier PHP : si opcache sert une image figée, la constante est figée avec
 * elle, et l'écart devient visible.
 *
 * ⚠ **L'ABSENCE DU FICHIER EST UNE RÉPONSE, PAS UNE ERREUR.** En développement local, personne n'a
 * déployé : il n'y a pas de commit chargé, et c'est normal. Rendre 500 ferait passer un état normal
 * pour une panne ; rendre un commit inventé serait pire. On dit « inconnu » et on dit pourquoi.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class LoadedBuildProvider implements ProviderInterface
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $chemin = $this->projectDir . '/var/build-version.php';

        if (!is_file($chemin)) {
            return new JsonResponse([
                'commit' => null,
                'detail' => 'Aucun marqueur de version chargé : cette instance n’a pas été déployée par ./infra/deploy-preprod.sh.',
            ]);
        }

        /** @var mixed $marqueur */
        $marqueur = require $chemin;

        if (!\is_array($marqueur) || !isset($marqueur['commit']) || !\is_string($marqueur['commit'])) {
            return new JsonResponse([
                'commit' => null,
                'detail' => 'Marqueur de version illisible.',
            ]);
        }

        return new JsonResponse([
            'commit' => $marqueur['commit'],
            // L'instant où le marqueur a été écrit, pas celui où FPM a démarré : les deux diffèrent
            // exactement dans le cas qu'on cherche à voir.
            'ecrit' => $marqueur['ecrit'] ?? null,
        ]);
    }
}
