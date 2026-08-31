<?php

declare(strict_types=1);

namespace App\Platform\Command;

use App\Platform\Entity\ScheduledTaskRun;
use App\Platform\Scheduling\ScheduleCatalog;
use App\Platform\Scheduling\ScheduledTask;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `platform:scheduler:run` — l'unique appel périodique dont la plateforme a besoin.
 *
 * **Ce qu'elle remplace.** Vingt-deux commandes de domaine portaient chacune dans leur en-tête une
 * variante de « à planifier via un cron externe au code applicatif ». Personne ne l'avait fait, et rien
 * ne le signalait : deux élévations de privilèges qui n'expirent jamais, le no-show qui ne bascule
 * jamais, la conservation des données qui ne s'applique jamais. Vingt-deux entrées de cron, ce sont
 * vingt-deux occasions d'en oublier une et aucun endroit où lire ce qui est censé tourner.
 *
 * **Ce qu'elle ne fait pas, et qu'il faut dire.** Elle ne se lance pas toute seule. Il reste UN appel
 * extérieur à poser — `platform:scheduler:run` toutes les minutes. Tant que personne ne le fait, rien
 * ne tourne, et c'est exactement le défaut qu'on répare. La différence est que **l'absence devient
 * visible** : `--status` dit, tâche par tâche, quand elle a tourné pour la dernière fois, et le dit en
 * rouge si la réponse est « jamais ».
 *
 * **Une tâche qui échoue n'arrête pas les autres.** Les dix-huit sont indépendantes ; laisser la
 * première en échec empêcher la purge des documents serait transformer un incident en panne.
 */
#[AsCommand(
    name: 'platform:scheduler:run',
    description: "Exécute les tâches périodiques dues. Seul appel externe dont la plateforme a besoin.",
)]
final class RunScheduledTasksCommand extends Command
{
    public function __construct(
        private readonly ScheduleCatalog $catalog,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_NONE, "N'exécute rien : dit ce qui a tourné et quand.")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dit ce qui serait exécuté, sans le faire.')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Ne traite que cette commande.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        if ($input->getOption('status')) {
            return $this->showStatus($io, $now);
        }

        $only = $input->getOption('only');
        $dryRun = (bool) $input->getOption('dry-run');
        $executees = 0;
        $echouees = 0;
        $attente = 0;

        foreach ($this->catalog->all() as $task) {
            if (\is_string($only) && $only !== '' && $task->command !== $only) {
                continue;
            }

            $trace = $this->traceFor($task->command);

            if (!$trace->isDue($now, $task->everyMinutes)) {
                continue;
            }

            $premierPassage = $trace->getLastFinishedAt() === null;
            $supervise = \is_string($only) && $only !== '';

            if ($premierPassage && !$task->safeOnFirstRun && !$supervise) {
                // Une commande qui n'a jamais tourné peut rattraper tout l'historique d'un coup — et
                // certaines pilotent du matériel. Tant que personne n'a regardé ce qu'elle fait, on ne
                // la lance pas toute seule. Ce n'est pas un blocage : `--premier-passage` ou `--only`
                // la lancent sous supervision, et elle se planifie normalement ensuite.
                $io->writeln(sprintf(
                    '  <comment>premier passage</comment> %s — lancer sous supervision : '
                    . 'bin/console platform:scheduler:run --only=%s',
                    $task->command,
                    $task->command,
                ));
                ++$attente;
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf('  <comment>due</comment> %s', $task->command));
                ++$executees;
                continue;
            }

            $code = $this->runOne($task, $trace, $now, $io);
            $code === Command::SUCCESS ? ++$executees : ++$echouees;
        }

        if ($attente > 0) {
            $io->note(sprintf(
                "%d tâche(s) attendent un premier passage supervisé. Tant qu'il n'a pas eu lieu, "
                . "elles ne tournent pas — voir `--only` ci-dessus.",
                $attente,
            ));
        }

        if ($echouees > 0) {
            $io->warning(sprintf('%d exécutée(s), %d en échec.', $executees, $echouees));

            // Échec signalé, mais pas propagé : l'appel extérieur ne doit pas s'arrêter de tourner
            // parce qu'une tâche a échoué une fois. `--status` porte la trace.
            return Command::SUCCESS;
        }

        if ($executees > 0) {
            $io->success(sprintf('%d tâche(s) exécutée(s).', $executees));
        }

        return Command::SUCCESS;
    }

    private function runOne(ScheduledTask $task, ScheduledTaskRun $trace, \DateTimeImmutable $now, SymfonyStyle $io): int
    {
        $application = $this->getApplication();
        if ($application === null) {
            return Command::FAILURE;
        }

        $trace->markStarted($now);
        $this->entityManager->persist($trace);
        $this->entityManager->flush();

        $tampon = new BufferedOutput();

        try {
            $code = $application->find($task->command)->run(new ArrayInput([]), $tampon);
            $erreur = $code === Command::SUCCESS ? null : $tampon->fetch();
        } catch (\Throwable $e) {
            $code = Command::FAILURE;
            $erreur = $e::class . ' : ' . $e->getMessage();
        }

        $trace->markFinished(new \DateTimeImmutable(), $code, $erreur);
        $this->entityManager->flush();

        if ($code === Command::SUCCESS) {
            $io->writeln(sprintf('  <info>ok</info> %s', $task->command));
        } else {
            $io->writeln(sprintf('  <error>KO</error> %s — %s', $task->command, mb_substr((string) $erreur, 0, 160)));
        }

        return $code;
    }

    private function showStatus(SymfonyStyle $io, \DateTimeImmutable $now): int
    {
        $lignes = [];
        $jamais = 0;
        $enRetard = 0;

        foreach ($this->catalog->all() as $task) {
            $trace = $this->traceFor($task->command);
            $fin = $trace->getLastFinishedAt();

            if ($fin === null) {
                $etat = 'JAMAIS';
                ++$jamais;
            } elseif ($trace->isDue($now, $task->everyMinutes)) {
                $etat = 'en retard';
                ++$enRetard;
            } else {
                $etat = 'à jour';
            }

            $lignes[] = [
                $task->critical ? '⚠' : '',
                $task->command,
                sprintf('%d min', $task->everyMinutes),
                $etat,
                $fin?->format('d/m H:i') ?? '—',
                $trace->getFailureCount() > 0 ? sprintf('%d échec(s)', $trace->getFailureCount()) : '',
            ];
        }

        $io->table(['', 'commande', 'toutes les', 'état', 'dernière fin', 'échecs'], $lignes);

        if ($jamais === \count($lignes)) {
            $io->error(
                "AUCUNE tâche n'a jamais tourné. L'ordonnanceur n'est appelé par personne : "
                . 'il manque un appel périodique à `platform:scheduler:run`. '
                . "Tant qu'il manque, deux élévations de privilèges n'expirent pas et le no-show ne bascule jamais."
            );

            return Command::FAILURE;
        }

        if ($jamais > 0 || $enRetard > 0) {
            $io->warning(sprintf('%d jamais exécutée(s), %d en retard.', $jamais, $enRetard));
        } else {
            $io->success('Toutes les tâches sont à jour.');
        }

        return Command::SUCCESS;
    }

    private function traceFor(string $command): ScheduledTaskRun
    {
        $trace = $this->entityManager->getRepository(ScheduledTaskRun::class)
            ->findOneBy(['command' => $command]);

        return $trace instanceof ScheduledTaskRun ? $trace : new ScheduledTaskRun($command);
    }
}
