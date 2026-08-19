<?php

declare(strict_types=1);

namespace App\Platform\Module;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registre des modules installés (CONTRACT/noyau-commun.md, « Registre de modules »).
 *
 * Il répond à trois questions que personne ne peut trancher en lisant le code : quels modules existent,
 * lequel émet ou consomme tel événement, et le graphe de dépendances est-il sain.
 *
 * **Validation au démarrage (RG-PLAT-07).** Un `id` dupliqué, une dépendance inconnue ou un cycle font
 * échouer la construction. C'est délibérément brutal : une plateforme dont le graphe de modules est
 * incohérent produirait des activations partielles silencieuses, bien plus coûteuses à diagnostiquer
 * qu'un démarrage refusé avec un message explicite.
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleManifest> indexé par `id` */
    private array $modules = [];

    /**
     * @param iterable<ModuleManifest> $manifests
     */
    public function __construct(
        #[AutowireIterator('platform.module')]
        iterable $manifests,
    ) {
        foreach ($manifests as $manifest) {
            $id = $manifest->id();

            if (isset($this->modules[$id])) {
                throw new \LogicException(sprintf(
                    'Deux modules déclarent l\'identifiant « %s » : %s et %s. Un identifiant de module est unique.',
                    $id,
                    $this->modules[$id]::class,
                    $manifest::class
                ));
            }

            $this->modules[$id] = $manifest;
        }

        $this->assertDependenciesAreResolved();
        $this->assertNoDependencyCycle();
    }

    /** @return array<string, ModuleManifest> indexé par `id` */
    public function all(): array
    {
        return $this->modules;
    }

    public function has(string $id): bool
    {
        return isset($this->modules[$id]);
    }

    public function get(string $id): ModuleManifest
    {
        return $this->modules[$id] ?? throw new \InvalidArgumentException(sprintf(
            'Module inconnu : « %s ». Modules enregistrés : %s.',
            $id,
            $this->modules === [] ? '(aucun)' : implode(', ', array_keys($this->modules))
        ));
    }

    /**
     * Modules qui déclarent écouter cet événement. Sert la revue d'impact : avant de modifier la charge
     * utile d'un événement, on veut savoir qui le lit.
     *
     * @return list<ModuleManifest>
     */
    public function consumersOf(string $eventName): array
    {
        return array_values(array_filter(
            $this->modules,
            static fn (ModuleManifest $m): bool => \in_array($eventName, $m->eventsConsumed(), true)
        ));
    }

    /**
     * @return list<ModuleManifest>
     */
    public function emittersOf(string $eventName): array
    {
        return array_values(array_filter(
            $this->modules,
            static fn (ModuleManifest $m): bool => \in_array($eventName, $m->eventsEmitted(), true)
        ));
    }

    private function assertDependenciesAreResolved(): void
    {
        foreach ($this->modules as $id => $manifest) {
            foreach ($manifest->dependencies() as $dependency) {
                if (!isset($this->modules[$dependency])) {
                    throw new \LogicException(sprintf(
                        'Le module « %s » dépend de « %s », qui n\'est pas enregistré. Modules connus : %s.',
                        $id,
                        $dependency,
                        $this->modules === [] ? '(aucun)' : implode(', ', array_keys($this->modules))
                    ));
                }
            }
        }
    }

    /**
     * Parcours en profondeur à trois états (non visité / en cours / terminé). L'état « en cours »
     * rencontré à nouveau signe le cycle, et le chemin accumulé permet de le nommer entièrement —
     * sans quoi le message se réduirait à « il y a un cycle quelque part ».
     */
    private function assertNoDependencyCycle(): void
    {
        $state = [];

        foreach (array_keys($this->modules) as $id) {
            $this->walk($id, $state, []);
        }
    }

    /**
     * @param array<string, string> $state
     * @param list<string>          $path
     */
    private function walk(string $id, array &$state, array $path): void
    {
        if (($state[$id] ?? null) === 'done') {
            return;
        }

        if (($state[$id] ?? null) === 'in_progress') {
            $path[] = $id;
            $start = array_search($id, $path, true);

            throw new \LogicException(sprintf(
                'Cycle de dépendances entre modules : %s.',
                implode(' → ', \array_slice($path, $start === false ? 0 : $start))
            ));
        }

        $state[$id] = 'in_progress';
        $path[] = $id;

        foreach ($this->modules[$id]->dependencies() as $dependency) {
            $this->walk($dependency, $state, $path);
        }

        $state[$id] = 'done';
    }
}
