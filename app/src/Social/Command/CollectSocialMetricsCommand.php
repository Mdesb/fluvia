<?php

declare(strict_types=1);

namespace App\Social\Command;

use App\Social\Entity\SocialPublication;
use App\Social\Enum\SocialPublicationStatus;
use App\Social\Message\CollectSocialMetrics;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Collecte planifiée des statistiques (SOC-3, D14 contrainte 2).
 *
 * **Dès le premier jour, et c'est tout l'enjeu.** Les plateformes ne rendent leurs statistiques que
 * sur une fenêtre limitée : sans instantanés pris à partir de maintenant, l'historique n'existera pas,
 * et il sera irrattrapable — personne ne peut retrouver la portée d'un message d'il y a six mois.
 *
 * La commande ne collecte rien elle-même : elle **met en file**. Interroger cinq réseaux en série dans
 * une tâche planifiée ferait dépendre la collecte de tout l'établissement du réseau le plus lent, et
 * un quota dépassé sur l'un interromprait les autres.
 *
 * `--limit` borne chaque passage. Une borne est indispensable : le nombre de publications ne fait que
 * croître, et une collecte non bornée finirait par mettre en file plus de travail qu'un passage ne
 * peut en absorber. Ce qui n'entre pas dans un passage entre au suivant — et la commande **dit** ce
 * qu'elle a laissé, plutôt que de laisser croire qu'elle a tout couvert.
 */
#[AsCommand(
    name: 'social:collect-metrics',
    description: 'Met en file la collecte des statistiques des publications parues.',
)]
final class CollectSocialMetricsCommand extends Command
{
    private const DEFAULT_LIMIT = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de publications par passage.', (string) self::DEFAULT_LIMIT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));

        $qb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(SocialPublication::class, 'p')
            ->where('p.status = :published')
            ->andWhere('p.remotePostId IS NOT NULL')
            ->andWhere('p.metricsStoppedAt IS NULL')
            ->setParameter('published', SocialPublicationStatus::Published)
            ->orderBy('p.createdAt', 'ASC')
            ->setMaxResults($limit + 1);

        /** @var list<SocialPublication> $publications */
        $publications = $qb->getQuery()->getResult();

        $reste = \count($publications) > $limit;
        if ($reste) {
            array_pop($publications);
        }

        foreach ($publications as $publication) {
            $this->bus->dispatch(new CollectSocialMetrics((string) $publication->getId()));
        }

        $io->success(sprintf('%d publication(s) mise(s) en file pour collecte.', \count($publications)));
        if ($reste) {
            // Une borne silencieuse se lit comme « tout est couvert ». On dit ce qu'on a laissé.
            $io->warning(sprintf('Limite de %d atteinte : il reste des publications non traitées à ce passage.', $limit));
        }

        return Command::SUCCESS;
    }
}
