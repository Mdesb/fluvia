<?php

declare(strict_types=1);

namespace App\Vente\Command;

use App\Caisse\Entity\PointDeVente;
use App\Vente\Nf525\DailyClosureHandler;
use App\Vente\Nf525\Entity\DailyClosure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Arrête la veille sur chaque point de vente (D57).
 *
 * **Pourquoi c'est une tâche et pas un geste.** NF525 exige une clôture quotidienne. Une obligation
 * légale ne peut pas dépendre de ce que quelqu'un pense à faire : un exploitant qui oublie trois
 * semaines n'a pas été négligent, il a rencontré un produit qui lui demandait d'être un mécanisme.
 *
 * **Et pourquoi ce qui échoue ne part pas dans un journal.** Une clôture manquée *en silence* est le
 * défaut de D57 reproduit un cran plus haut : on échangerait un oubli visible contre un oubli
 * invisible, et le second est pire parce que **tout le monde croirait que c'est fait**. Ce qui n'a pas
 * pu être arrêté reste donc dans la file des journées non closes (`PendingClosuresProvider`), avec sa
 * raison — une liste qui descend à zéro, pas une ligne de journal que personne ne relit.
 *
 * **La veille, dans le fuseau de l'établissement.** À 3 h du matin à Paris il est encore 21 h la
 * veille aux Antilles : clôturer « hier » au sens du serveur y arrêterait une journée **en cours**. Le
 * fuseau vient de `Etablissement::getFuseauHoraire()`, ajouté pour ce lot.
 *
 * **`--dry-run` n'est pas un confort.** Une clôture *scelle*. Un exploitant qui découvre trois
 * semaines d'arriéré doit pouvoir voir ce qui partirait avant de décider — montrer avant de faire est
 * la moindre des choses sur un geste irréversible. C'est aussi pourquoi la tâche est déclarée
 * `safeOnFirstRun: false` : un premier passage sur un arriéré produirait vingt et un arrêtés d'un
 * coup, et aucun ne se retire.
 */
#[AsCommand(
    name: 'vente:cloture:journee',
    description: 'Arrête la veille sur chaque point de vente (clôture journalière NF525).',
)]
final class CloseBusinessDayCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DailyClosureHandler $handler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Montre les journées qui seraient arrêtées, sans rien sceller.',
        );
        $this->addOption(
            'point-de-vente',
            null,
            InputOption::VALUE_REQUIRED,
            'Restreint à un point de vente (identifiant), pour un rattrapage supervisé.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('dry-run');

        $criteres = [];
        $cible = $input->getOption('point-de-vente');
        if (\is_string($cible) && $cible !== '') {
            $criteres['id'] = $cible;
        }

        /** @var list<PointDeVente> $points */
        $points = $this->em->getRepository(PointDeVente::class)->findBy($criteres);
        if ($points === []) {
            $io->warning('Aucun point de vente : rien à arrêter.');

            return Command::SUCCESS;
        }

        $arretees = 0;
        $refusees = [];

        foreach ($points as $pdv) {
            $etablissement = $pdv->getEtablissement();
            if ($etablissement === null || !$etablissement->isActif()) {
                // Un établissement fermé n'encaisse plus : lui poser des arrêtés quotidiens
                // indéfiniment remplirait la chaîne de clôtures à zéro, et la file de rien.
                continue;
            }

            $fuseau = new \DateTimeZone($etablissement->getFuseauHoraire());
            $veille = (new \DateTimeImmutable('now', $fuseau))->modify('-1 day')->setTime(0, 0);

            if ($simulation) {
                $io->writeln(sprintf(
                    '  <info>%s</info> (%s, %s) → journée du %s',
                    $pdv->getLibelle(),
                    $etablissement->getNom(),
                    $etablissement->getFuseauHoraire(),
                    $veille->format('Y-m-d'),
                ));
                ++$arretees;

                continue;
            }

            try {
                $cloture = $this->handler->close($pdv, $veille, null);
                ++$arretees;
                $io->writeln(sprintf(
                    '  <info>%s</info> — %s : %d vente(s), cumul %s',
                    $pdv->getLibelle(),
                    $cloture->getBusinessDay()->format('Y-m-d'),
                    $cloture->getSalesCount(),
                    $cloture->getGrandTotal(),
                ));
            } catch (\Throwable $e) {
                // **Rien n'est avalé.** Ce qui échoue ici reste une journée non close, donc une entrée
                // de la file — et la raison voyage avec, sinon on cherche au mauvais endroit.
                $refusees[] = sprintf('%s (%s) : %s', $pdv->getLibelle(), $veille->format('Y-m-d'), $e->getMessage());
            }
        }

        if ($simulation) {
            $io->success(sprintf('%d journée(s) seraient arrêtées. Rien n\'a été scellé.', $arretees));

            return Command::SUCCESS;
        }

        $io->writeln('');
        $io->writeln(sprintf('%d journée(s) arrêtée(s).', $arretees));

        if ($refusees !== []) {
            // Un échec n'est pas un incident d'exécution : c'est une journée qui reste ouverte, et
            // elle doit se voir. Le code de retour le dit à l'ordonnanceur ; la file le dit à l'humain.
            $io->warning(sprintf('%d journée(s) NON close(s) — elles restent dans la file :', \count($refusees)));
            $io->listing($refusees);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
