<?php

declare(strict_types=1);

namespace App\Platform\Scheduling;

/**
 * Une tâche périodique déclarée : quelle commande, à quelle fréquence, et **pourquoi**.
 *
 * Le « pourquoi » n'est pas de la documentation de politesse. Une fréquence sans justification se
 * modifie au jugé, et c'est ainsi qu'une purge quotidienne devient horaire parce que quelqu'un trouvait
 * ça « plus sûr ». Ici la raison voyage avec la valeur.
 */
final readonly class ScheduledTask
{
    /**
     * @param non-empty-string $command    nom exact de la commande console
     * @param positive-int     $everyMinutes intervalle minimal entre deux exécutions
     * @param non-empty-string $why        ce qui se passe si elle ne tourne pas
     */
    public function __construct(
        public string $command,
        public int $everyMinutes,
        public string $why,
        public bool $critical = false,
    ) {
    }
}
