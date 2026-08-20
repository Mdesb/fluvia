<?php

declare(strict_types=1);

namespace App\Platform\Command;

use App\Platform\Module\ModuleManifest;
use App\Platform\Module\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Inspection du registre de modules — l'équivalent lisible de « qu'est-ce qui est installé, et qui parle
 * à qui ». Pas d'écran d'administration en v0 : cette commande suffit à la revue et au débogage, et elle
 * a l'avantage de faire échouer le démarrage si le graphe est incohérent (RG-PLAT-07).
 */
#[AsCommand(
    name: 'platform:modules',
    description: 'Liste les modules enregistrés, leurs dépendances et leurs événements.',
)]
final class ListModulesCommand extends Command
{
    public function __construct(
        private readonly ModuleRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $modules = $this->registry->all();

        if ($modules === []) {
            $io->warning('Aucun module enregistré. Un module se déclare en implémentant App\Platform\Module\ModuleManifest.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Modules enregistrés (%d)', \count($modules)));

        $io->table(
            ['id', 'version', 'capacité', 'dépend de', 'émet', 'consomme', 'features'],
            array_map(
                static fn (ModuleManifest $m): array => [
                    $m->id(),
                    $m->version(),
                    $m->capability() ?? '— (transverse)',
                    implode(', ', $m->dependencies()) ?: '—',
                    implode(', ', $m->eventsEmitted()) ?: '—',
                    implode(', ', $m->eventsConsumed()) ?: '—',
                    implode(', ', $m->features()) ?: '—',
                ],
                array_values($modules)
            )
        );

        return Command::SUCCESS;
    }
}
