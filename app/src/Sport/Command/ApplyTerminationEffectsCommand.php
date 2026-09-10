<?php

declare(strict_types=1);

namespace App\Sport\Command;

use App\Membership\Entity\Resiliation;
use App\Membership\Enum\StatutResiliation;
use App\Membership\Service\DemanderResiliationHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * L'EFFET DES RÉSILIATIONS ARRIVÉES À LEUR DATE D'EFFET.
 *
 * ── CE QUI MANQUAIT, ET DEPUIS TOUJOURS ────────────────────────────────────────────────────────
 *
 * `DemanderResiliationHandler::executerEffet()` est le seul code du dépôt qui passe l'abonnement en
 * `Resilie`, révoque le mandat SEPA, annule les échéances postérieures et coupe l'accès. Mesuré le
 * 06/09 : le nom `executerEffet` apparaissait **trois fois dans tout le dépôt** — sa propre
 * déclaration et deux commentaires. Aucun appelant. Ni opération d'API, ni tâche planifiée.
 *
 * Conséquence pour l'adhérent : il résilie, le préavis court, la date d'effet passe — et rien
 * n'arrive. Il est encore prélevé, son badge ouvre encore la porte, et la phrase de l'écran (« À sa
 * date d'effet, l'accès est coupé et le mandat SEPA est révoqué ») promet un mécanisme absent.
 *
 * ── POURQUOI UNE COMMANDE À PART, ET PAS UNE LIGNE DANS `sport:abonnements:traiter-terme` ───────
 *
 * La tâche voisine tourne déjà, et son verrou de premier passage est levé depuis le 04/09. Y
 * greffer cet effet-ci le ferait partir dès la nuit suivante, sans que personne ait vu la liste —
 * or couper des accès et révoquer des mandats est un « effet visible au dehors » (catégorie 3 de
 * `ScheduleCatalog`), et sur un parc réel le premier passage traiterait d'un coup toutes les
 * résiliations dont la date d'effet est déjà passée. Se greffer sur une porte déjà ouverte, c'est
 * contourner D109 sans le dire. Cette commande porte donc son propre verrou.
 *
 * ── ⚠ ET ELLE DOIT PASSER AVANT `sport:abonnements:traiter-terme` ──────────────────────────────
 *
 * Une résiliation en préavis laisse l'abonnement `Actif` jusqu'à l'effet. Or `traiter-terme`
 * sélectionne exactement les abonnements `Actif` arrivés au terme et RECONDUIT leur engagement —
 * et il ignore complètement les résiliations : `Resiliation` n'apparaît ni dans
 * `ProcessSubscriptionTermsCommand` ni dans `SubscriptionTermHandler` (0 occurrence dans les deux).
 * Si les deux tombent la même nuit et que le terme passe en premier, l'adhérent qui a résilié se
 * voit reconduit pour un an, la nuit même où sa résiliation devait prendre effet.
 *
 * D'où `order: 5` au catalogue, contre `order: 10` pour le terme.
 */
#[AsCommand(
    name: 'sport:resiliations:appliquer',
    description: "Applique l'effet des résiliations dont la date d'effet est arrivée.",
)]
final class ApplyTerminationEffectsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DemanderResiliationHandler $handler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dit ce qui serait appliqué, sans le faire.')
            ->addOption('le', null, InputOption::VALUE_REQUIRED, 'Date de référence (ISO), pour rejouer un jour précis.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('dry-run');
        $le = $input->getOption('le');
        $jour = (\is_string($le) && $le !== '' ? new \DateTimeImmutable($le) : new \DateTimeImmutable())
            ->setTime(0, 0);

        /*
         * ⚠ SEULEMENT `EnPreavis`. `executerEffet()` refuse tout autre statut, et il a raison : une
         * résiliation `Refusee` n'a pas d'effet à appliquer, et une `Effective` l'a déjà eu. Filtrer
         * ici plutôt que de rattraper l'exception évite de compter des échecs qui n'en sont pas.
         *
         * ⚠ ET `Refusee` PORTE DEUX SENS DANS CE DOMAINE — « refusée » et « en attente de validation
         *   du motif légitime » (voir le docblock de `DemanderResiliationHandler`). Ni l'un ni
         *   l'autre ne doit prendre effet tout seul : la validation est un geste humain explicite
         *   (`POST /sport/resiliations/{id}/valider-motif-legitime`), qui bascule en `EnPreavis`.
         *   C'est à ce moment-là, et pas avant, que cette commande la voit.
         */
        /** @var list<Resiliation> $dues */
        $dues = $this->em->getRepository(Resiliation::class)
            ->createQueryBuilder('r')
            ->andWhere('r.statut = :preavis')
            ->andWhere('r.dateEffet <= :jour')
            ->setParameter('preavis', StatutResiliation::EnPreavis)
            ->setParameter('jour', $jour)
            ->orderBy('r.dateEffet', 'ASC')
            ->getQuery()
            ->getResult();

        if ($dues === []) {
            $io->writeln('Aucune résiliation arrivée à sa date d\'effet.');

            return Command::SUCCESS;
        }

        $appliquees = 0;
        $refusees = [];

        foreach ($dues as $resiliation) {
            $abonnement = $resiliation->getAbonnement();
            $etiquette = sprintf(
                '%s — effet au %s',
                $abonnement?->getId() ?? '(abonnement absent)',
                $resiliation->getDateEffet()->format('Y-m-d'),
            );

            if ($simulation) {
                $io->writeln(sprintf('  <info>%s</info>', $etiquette));
                ++$appliquees;

                continue;
            }

            try {
                $this->handler->executerEffet($resiliation);
                ++$appliquees;
                $io->writeln(sprintf('  <info>%s</info> — accès coupé, échéances restantes annulées', $etiquette));
            } catch (\Throwable $e) {
                /*
                 * ⚠ RIEN N'EST AVALÉ, ET UN ÉCHEC N'ARRÊTE PAS LES AUTRES. Une résiliation qui ne
                 * peut pas prendre effet reste `EnPreavis` : elle repassera au prochain cycle, et
                 * sa raison est nommée ici. L'avaler en silence rendrait un adhérent encore
                 * prélevé indiscernable d'un adhérent correctement résilié.
                 */
                $refusees[] = sprintf('%s : %s', $etiquette, $e->getMessage());
            }
        }

        if ($simulation) {
            $io->success(sprintf('%d résiliation(s) prendraient effet. Rien n\'a été appliqué.', $appliquees));

            return Command::SUCCESS;
        }

        $io->writeln('');
        $io->writeln(sprintf('%d résiliation(s) appliquée(s).', $appliquees));

        if ($refusees !== []) {
            $io->warning(sprintf('%d résiliation(s) NON appliquée(s) — elles restent en préavis :', \count($refusees)));
            foreach ($refusees as $raison) {
                $io->writeln(sprintf('  - %s', $raison));
            }
        }

        return Command::SUCCESS;
    }
}
