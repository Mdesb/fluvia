<?php

declare(strict_types=1);

namespace App\Reporting\Command;

use App\Reporting\Enum\GranulariteMesure;
use App\Reporting\Service\AgregateurMesuresService;
use App\Reporting\ValueObject\Periode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `reporting:agreger` (§2.9 plan-reporting.md) : trois passes site → région → groupe, idempotent
 * (`Mesure.cleAgregation`). Sans option, agrège le jour courant. `--depuis`/`--jusqu-a` (Y-m-d)
 * permettent un rattrapage jour par jour. Ordonnancement via cron externe (§8, pas de nouveau
 * worker permanent, même arbitrage que `securite:delegations:expirer`).
 */
#[AsCommand(
    name: 'reporting:agreger',
    description: 'Agrège les Mesure de reporting (site puis région puis groupe) pour une ou plusieurs journées.',
)]
final class AgregerMesuresCommand extends Command
{
    public function __construct(
        private readonly AgregateurMesuresService $agregateur,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('depuis', null, InputOption::VALUE_REQUIRED, 'Date de début (Y-m-d), défaut = aujourd\'hui')
            ->addOption('jusqu-a', null, InputOption::VALUE_REQUIRED, 'Date de fin (Y-m-d), défaut = --depuis');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $depuisOption = $input->getOption('depuis');
        $jusquaOption = $input->getOption('jusqu-a');

        $depuis = \is_string($depuisOption) ? new \DateTimeImmutable($depuisOption) : new \DateTimeImmutable('today');
        $jusqua = \is_string($jusquaOption) ? new \DateTimeImmutable($jusquaOption) : $depuis;

        $jours = 0;
        $courant = $depuis;
        while ($courant <= $jusqua) {
            $periode = new Periode($courant->setTime(0, 0, 0), $courant->setTime(23, 59, 59), GranulariteMesure::Jour);
            $this->agregateur->agregerPeriode($periode);
            ++$jours;
            $courant = $courant->modify('+1 day');
        }

        $io->success(sprintf('%d journée(s) agrégée(s) (site → région → groupe).', $jours));

        return Command::SUCCESS;
    }
}
