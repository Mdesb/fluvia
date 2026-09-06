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
 * **`--dry-run` n'est pas un confort.** Une clôture *scelle*, et rien ne la retire
 * (`InalterabiliteListener` refuse `preRemove` comme `preUpdate`). Montrer avant de faire est donc
 * la moindre des choses.
 *
 * ⚠ ET IL A LONGTEMPS MONTRÉ AUTRE CHOSE. Jusqu'au 06/09, la branche de simulation faisait
 *   `continue` avant `close()` : elle n'évaluait aucun des trois refus, comptait un arrêté par point
 *   de vente actif, et concluait « N journées seraient arrêtées ». Elle annonçait 7 là où
 *   l'exécution en aurait scellé 5 et refusé 2. Elle consulte désormais
 *   `DailyClosureHandler::raisonDeRefus()` — la même méthode que `close()`, pas une copie.
 *
 * ⚠ ET CETTE PHRASE DISAIT « UN PREMIER PASSAGE SUR UN ARRIÉRÉ PRODUIRAIT VINGT ET UN ARRÊTÉS D'UN
 *   COUP ». C'est faux : `execute()` ne ferme que LA VEILLE, une fois par point de vente, sans
 *   jamais boucler sur les jours. Un arriéré ne produit pas vingt et un arrêtés — il produit un
 *   REFUS, parce que la chaîne des cumuls interdit de sauter une journée porteuse de ventes.
 *   `safeOnFirstRun: false` reste juste, pour l'autre raison : le scellé est définitif, et un arrêté
 *   posé sur le mauvais jour ou avec la mauvaise clé ne se défait pas.
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
                // ⚠ LA SIMULATION POSE LA MÊME QUESTION QUE L'EXÉCUTION. Elle sautait ce test et
                //   annonçait un arrêté par point de vente actif — donc l'inverse du réel dès qu'un
                //   arriéré existe, sur l'instrument même qui doit éclairer un geste irréversible.
                $refus = $this->handler->raisonDeRefus($pdv, $veille);
                if ($refus !== null) {
                    $refusees[] = sprintf('%s (%s) : %s', $pdv->getLibelle(), $veille->format('Y-m-d'), $refus);

                    continue;
                }

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

            // ⚠ ET CE QUI SERAIT REFUSÉ, SINON LE COMPTE MENT PAR OMISSION. Un exploitant qui lit
            //   « 5 journées seraient arrêtées » sans voir les deux refus croit son parc à jour.
            if ($refusees !== []) {
                $io->warning(sprintf('%d journée(s) seraient REFUSÉE(S) :', \count($refusees)));
                foreach ($refusees as $raison) {
                    $io->writeln(sprintf('  - %s', $raison));
                }
                $io->writeln(
                    "  Une journée antérieure porteuse de ventes bloque la chaîne des cumuls. Elle se "
                    . "clôt une par une, de la plus ancienne à la plus récente, par "
                    . "<comment>POST /api/point_de_ventes/{id}/cloture-journaliere</comment> avec "
                    . "<comment>{\"journee\": \"AAAA-MM-JJ\"}</comment> — la liste est servie par "
                    . "<comment>/api/clotures-journalieres/en-attente</comment>."
                );
            }

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
