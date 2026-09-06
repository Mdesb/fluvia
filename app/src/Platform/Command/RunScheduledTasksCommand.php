<?php

declare(strict_types=1);

namespace App\Platform\Command;

use App\Platform\Entity\ScheduledTaskRun;
use App\Platform\Scheduling\ScheduleCatalog;
use App\Platform\Scheduling\NightlyWindow;
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
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Ne traite que cette commande.')
            // La supervision se REVENDIQUE, elle ne s'infere pas de la forme de l'appel (D91).
            ->addOption(
                'supervise',
                null,
                InputOption::VALUE_NONE,
                "Atteste qu'un humain regarde ce passage. Absent par defaut.",
            );
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

        // ⚠ L'ORDRE EST DECLARE, PAS CELUI DU CATALOGUE. Le renouvellement cree les echeances que
        // le preavis annonce et que la facturation encaisse : les lancer dans le desordre ferait
        // annoncer un preavis sur une echeance inexistante — donc rien, sans erreur.
        //
        // `usort` est stable depuis PHP 8.0 : deux taches de meme rang gardent l'ordre du catalogue.
        // C'est ce qu'on veut — le rang exprime une dependance, pas un classement total, et la
        // plupart des taches n'en ont aucune.
        $taches = $this->catalog->all();
        usort($taches, static fn (ScheduledTask $a, ScheduledTask $b): int => $a->order <=> $b->order);

        // ⚠ UN `--only` QUI NE DÉSIGNE RIEN SORTAIT EN SILENCE, CODE 0.
        //
        // Trouvé en l'exécutant, pas en le lisant : `--only=tache:qui:nexiste:pas` ne produisait
        // aucune sortie et rendait 0. La boucle ci-dessous filtre par égalité de nom ; un nom
        // absent du catalogue ne fait que sauter chaque tour, et la commande se termine « avec
        // succès ».
        //
        // ⚠ CE N'ÉTAIT PAS THÉORIQUE, ET ÇA A COÛTÉ UNE TÂCHE ENTIÈRE. `infra/ordonnanceur.sh`
        //   appelle `--only=<nom>` pour chaque nom de sa liste blanche.
        //   `reservation:confirmations:expirer` y figurait et manquait au catalogue : à chaque
        //   cycle l'ordonnanceur lançait, recevait 0, écrivait « ok », et rien ne tournait.
        //   `--status` ne pouvait pas le dire non plus — il ne connaît que le catalogue, donc la
        //   tâche n'y apparaissait même pas comme « JAMAIS ».
        //
        // Une absence ne crie pas toute seule. On la rend bruyante ici, où elle naît, et pas
        // seulement dans le garde-fou qui compare les deux listes au moment du commit : le
        // garde-fou protège le dépôt, celui-ci protège l'exécution.
        if (\is_string($only) && $only !== '') {
            $connues = array_map(static fn (ScheduledTask $t): string => $t->command, $taches);
            if (!\in_array($only, $connues, true)) {
                $io->error(sprintf('« %s » n\'est pas une tâche du catalogue.', $only));
                $io->writeln(
                    "  Rien n'a été exécuté. Une tâche absente du catalogue ne tourne jamais, et "
                    . "<comment>--status</comment> ne peut pas la signaler : il ne connaît que le catalogue."
                );
                $io->writeln(
                    "  → l'ajouter dans <comment>src/Platform/Scheduling/ScheduleCatalog.php</comment>, "
                    . "ou la retirer de <comment>TACHES_AUTORISEES</comment> dans <comment>infra/ordonnanceur.sh</comment>."
                );

                return Command::FAILURE;
            }
        }

        foreach ($taches as $task) {
            if (\is_string($only) && $only !== '' && $task->command !== $only) {
                continue;
            }

            $trace = $this->traceFor($task->command);

            // ⚠ LA FENETRE REMPLACE L'INTERVALLE, ELLE NE S'Y AJOUTE PAS.
            //
            // Une tache nocturne est due « une fois par nuit locale, dans sa fenetre ». Laisser
            // `everyMinutes` se prononcer aussi donnerait deux verites sur la meme tache, et la plus
            // permissive gagnerait au premier desaccord.
            // ⚠ ET LA SUPERVISION PRIME SUR L'HEURE.
            //
            // Trouve en executant, pas par les tests : le premier passage supervise d'une tache
            // nocturne etait IMPOSSIBLE hors de la fenetre. A 23h50, `--supervise` ne produisait
            // aucune sortie — la fenetre refusait avant que le verrou de premier passage ait son
            // mot a dire. On demandait donc a un humain d'attester qu'il regarde, entre 02h00 et
            // 05h00, une nuit ou il est reveille.
            //
            // La fenetre protege de deux choses : le pic de base en pleine journee, et l'effet
            // visible au dehors a une heure ou personne ne l'attend. Quelqu'un qui lance la tache a
            // la main accepte les deux explicitement. C'est le sens meme de `--supervise`.
            $supervisionExplicite = (bool) $input->getOption('supervise');

            $due = $task->nightlyAt === null || $supervisionExplicite
                ? $trace->isDue($now, $task->everyMinutes)
                : NightlyWindow::isOpen($now, $task->nightlyAt, $trace->getLastFinishedAt());

            if (!$due) {
                continue;
            }

            $premierPassage = $trace->getLastFinishedAt() === null;

            // ⚠ CE N'ETAIT PAS CA. La ligne disait `$supervise = is_string($only) && $only !== ''`,
            // c'est-a-dire « lance avec --only » = « regarde par un humain ». Or `infra/ordonnanceur.sh`
            // appelle --only pour chaque tache a chaque cycle : $supervise etait toujours vrai, et le
            // verrou ci-dessous n'a jamais retenu une seule execution depuis qu'il existe.
            // Mesure de `allaccess-b8`, 01/09. Voir D91.
            $supervise = (bool) $input->getOption('supervise');

            // ⚠ `--dry-run` NE DOIT PAS ÊTRE RETENU PAR LE VERROU DE PREMIER PASSAGE.
            //
            // Le message du verrou, juste en dessous, conseille « Ce que cette exécution ferait :
            // --dry-run » — et `--dry-run` tombait sur ce même message, parce que le verrou était
            // testé d'abord. L'avis renvoyait à lui-même, pour TOUTE tâche sous verrou : mesuré
            // sur `vente:cloture:journee` autant que sur `reservation:confirmations:expirer`. Il
            // fallait deviner `--supervise --dry-run`, c'est-à-dire attester qu'on regarde pour
            // avoir le droit de regarder.
            //
            // Un passage à blanc n'écrit rien. Le verrou protège l'EXÉCUTION — « on ne la lance
            // pas toute seule » — pas la lecture ; et c'est précisément la lecture qui doit
            // précéder la décision de superviser un premier passage.
            //
            // ⚠ ET IL DIT LES DEUX CHOSES. « due » seul ferait croire qu'elle partirait au
            //   prochain cycle, alors que le verrou la retiendra. Un passage à blanc qui cache
            //   ce qui bloque serait une autre façon de mentir.
            // ⚠ LA MÊME CONDITION QUE LE VERROU, À L'IDENTIQUE — pas une qui lui ressemble.
            //
            //   Ma première version omettait `!$supervise` : `--supervise --dry-run` annonçait
            //   « retenue » sur une tâche que le verrou allait laisser passer. Le passage à blanc
            //   décrivait un blocage qui n'aurait pas eu lieu.
            //
            //   Deux expressions qui doivent dire la même chose divergent toujours. Celle-ci est
            //   calculée une fois et lue deux fois.
            $retenue = $premierPassage && !$task->safeOnFirstRun && !$supervise;

            if ($dryRun) {
                $io->writeln(sprintf(
                    '  <comment>due</comment> %s%s',
                    $task->command,
                    $retenue ? ' — <comment>retenue</comment> : premier passage, attend une exécution supervisée (D109)' : '',
                ));
                ++$executees;
                continue;
            }

            if ($retenue) {
                // Une commande qui n'a jamais tourné peut rattraper tout l'historique d'un coup — et
                // certaines pilotent du matériel. Tant que personne n'a regardé ce qu'elle fait, on ne
                // la lance pas toute seule.
                //
                // ⚠ CE MESSAGE NE DIT PLUS COMMENT PASSER OUTRE (D53). Il disait « lancer sous
                // supervision : --only=<tache> » — et cette porte-la etait justement celle qui
                // s'ouvrait toute seule a chaque cycle. Un message d'echec dit ce qui est bloque et
                // ce qu'on peut regarder ; la levee vit dans la documentation, pas dans la sortie.
                // ⚠ CE MESSAGE A ETE VU S'AFFICHER, PAS SEULEMENT RELU.
                //
                // Sa premiere version portait « Ce qu'ette execution ferait » : une apostrophe
                // evitee par un `%s` avait mange le mot. Et elle renvoyait a « D91 » alors que la
                // decision a ete renumerotee D109 le meme jour -- D91 existe et parle d'autre
                // chose, donc la reference ne menait pas nulle part, elle menait ailleurs.
                //
                // La chaine est desormais en guillemets doubles : plus d'apostrophe a contourner.
                $io->writeln(sprintf(
                    "  <comment>premier passage</comment> %s — jamais exécutée, et non marquée sûre "
                    . "au premier passage : elle rattraperait tout son retard en une fois. "
                    . "Ce que cette exécution ferait : --dry-run. Ce qui a déjà tourné : --status. "
                    . "La levée est décrite en D109.",
                    $task->command,
                ));
                ++$attente;
                continue;
            }

            $code = $this->runOne($task, $trace, $now, $io);
            $code === Command::SUCCESS ? ++$executees : ++$echouees;
        }

        if ($attente > 0) {
            $io->note(sprintf(
                "%d tâche(s) attendent un premier passage supervisé. Tant qu'il n'a pas eu lieu, "
                . "elles ne tournent pas, et c'est voulu — voir D109.",
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

    /**
     * Combien de fenetres nocturnes se sont ouvertes et refermees depuis la derniere execution ?
     *
     * ⚠ ON COMPTE LES NUITS, PAS LES MINUTES. Une tache nocturne qui n'a pas tourne depuis trois
     * jours n'est pas « en retard de 4320 minutes » : elle a manque trois nuits, et chacune est un
     * mois de facturation, un lot de preavis ou un renouvellement qui n'a pas eu lieu. Le nombre de
     * minutes ne se traduit pas en tete ; le nombre de nuits, si.
     *
     * La nuit EN COURS ne compte pas comme manquee : elle n'est pas encore passee.
     */
    private function nuitsManquees(
        \DateTimeImmutable $now,
        string $nightlyAt,
        \DateTimeImmutable $lastFinishedAt,
    ): int {
        $zone = new \DateTimeZone(NightlyWindow::TIMEZONE);
        $depuis = $lastFinishedAt->setTimezone($zone)->setTime(0, 0);
        $jusqu = $now->setTimezone($zone)->setTime(0, 0);

        $jours = (int) $depuis->diff($jusqu)->days;

        // Elle a tourne cette nuit (meme date locale) : rien de manque.
        if ($jours <= 0) {
            return 0;
        }

        // Si la fenetre de cette nuit n'est pas encore ouverte, la nuit en cours n'est pas manquee.
        $ouverte = NightlyWindow::isOpen($now, $nightlyAt, null);
        $fermee = !$ouverte && $now->setTimezone($zone)->format('H:i') > $nightlyAt;

        return $ouverte || $fermee ? $jours : $jours - 1;
    }

    private function showStatus(SymfonyStyle $io, \DateTimeImmutable $now): int
    {
        $lignes = [];
        $jamais = 0;
        $enRetard = 0;

        foreach ($this->catalog->all() as $task) {
            $trace = $this->traceFor($task->command);
            $fin = $trace->getLastFinishedAt();

            // ⚠ L'ETAT DOIT ETRE CALCULE COMME LA DECISION D'EXECUTION, PAS AUTREMENT.
            //
            // Une tache nocturne n'est pas « en retard » a 14h00 parce que 1440 minutes se sont
            // ecoulees : elle attend sa fenetre, et c'est normal. Lire l'intervalle ici ferait
            // afficher « en retard » a toute heure du jour sur des taches parfaitement a l'heure —
            // un tableau de bord rouge en permanence s'apprend a ne plus se lire.
            //
            // La contrepartie est qu'une nuit REELLEMENT manquee doit se voir. On la compte en
            // NUITS, pas en minutes : « 4320 minutes de retard » ne dit rien, « 3 nuits sautees »
            // se lit.
            if ($fin === null) {
                $etat = 'JAMAIS';
                ++$jamais;
            } elseif ($task->nightlyAt !== null) {
                $nuits = $this->nuitsManquees($now, $task->nightlyAt, $fin);

                if ($nuits > 0) {
                    $etat = sprintf('%d nuit(s) manquee(s)', $nuits);
                    ++$enRetard;
                } else {
                    $etat = 'à jour';
                }
            } elseif ($trace->isDue($now, $task->everyMinutes)) {
                $etat = 'en retard';
                ++$enRetard;
            } else {
                $etat = 'à jour';
            }

            $lignes[] = [
                $task->critical ? '⚠' : '',
                $task->command,
                $task->nightlyAt !== null ? sprintf('nuit %s', $task->nightlyAt) : sprintf('%d min', $task->everyMinutes),
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
