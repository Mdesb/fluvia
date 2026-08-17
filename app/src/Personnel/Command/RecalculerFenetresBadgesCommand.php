<?php

declare(strict_types=1);

namespace App\Personnel\Command;

use App\Personnel\Service\RecalculFenetreBadgeHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `personnel:recalculer-fenetres-badges` (cron ~5 min, décision n°3 du plan) : recalcule la fenêtre
 * de validité de tous les badges staff actifs en mode `shifts_uniquement`.
 */
#[AsCommand(name: 'personnel:recalculer-fenetres-badges', description: 'Recalcule les fenêtres de validité des badges staff (mode shifts_uniquement).')]
final class RecalculerFenetresBadgesCommand extends Command
{
    public function __construct(
        private readonly RecalculFenetreBadgeHandler $handler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->handler->recalculerTous();
        $output->writeln('Fenêtres de badges staff recalculées.');

        return Command::SUCCESS;
    }
}
