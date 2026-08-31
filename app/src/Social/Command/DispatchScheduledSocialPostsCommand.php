<?php

declare(strict_types=1);

namespace App\Social\Command;

use App\Social\Entity\SocialPublication;
use App\Social\Enum\SocialPublicationStatus;
use App\Social\Message\PublishSocialPublication;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Réveille les messages datés dont l'heure est venue (SOC-3).
 *
 * Comble le manque assumé de SOC-2 : un message daté y restait `scheduled` et ne partait jamais, faute
 * d'ordonnanceur. Il valait mieux un état qui dit la vérité qu'un envoi silencieusement immédiat ;
 * voici l'ordonnanceur.
 *
 * **La garde contre le doublon est le cœur de cette commande.** On ne reprend que les publications
 * jamais confiées à la file (`queuedAt` nul), et on pose la marque **immédiatement**, avant que le
 * worker n'ait la moindre chance de travailler. Deux passages rapprochés — un cron qui déborde, une
 * exécution manuelle par-dessus la planifiée — publieraient sinon deux fois le même message sur le fil
 * public d'un client. Un doublon paru ne se rattrape pas : on préfère donc, en cas de doute, un
 * message qui ne part pas à un message qui part deux fois. Le premier se voit dans l'état et se
 * relance ; le second, non.
 *
 * L'heure est passée en paramètre plutôt que lue de l'horloge : c'est ce qui rend la commande
 * vérifiable sans assertion d'horloge dans la suite (D20).
 */
#[AsCommand(
    name: 'social:dispatch-scheduled-posts',
    description: 'Met en file les publications des messages datés dont l heure est venue.',
)]
final class DispatchScheduledSocialPostsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('now', null, InputOption::VALUE_REQUIRED, 'Instant de reference (ISO 8601). Par defaut : maintenant.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $reference = $input->getOption('now');
        try {
            $now = is_string($reference) && $reference !== ''
                ? new \DateTimeImmutable($reference)
                : new \DateTimeImmutable();
        } catch (\Exception) {
            $io->error('Instant de reference illisible.');

            return Command::INVALID;
        }

        /** @var list<SocialPublication> $publications */
        $publications = $this->em->createQueryBuilder()
            ->select('p')
            ->from(SocialPublication::class, 'p')
            ->join('p.post', 'post')
            ->where('p.status = :pending')
            ->andWhere('p.queuedAt IS NULL')
            ->andWhere('post.scheduledFor IS NOT NULL')
            ->andWhere('post.scheduledFor <= :now')
            ->setParameter('pending', SocialPublicationStatus::Pending)
            ->setParameter('now', $now)
            ->orderBy('post.scheduledFor', 'ASC')
            ->getQuery()
            ->getResult();

        $mises = 0;
        foreach ($publications as $publication) {
            $this->bus->dispatch(new PublishSocialPublication((string) $publication->getId()));
            $publication->setQueuedAt($now);
            // Un enregistrement par publication, et non un seul à la fin : si le passage s'interrompt,
            // ce qui est parti est marqué comme parti. Un lot marqué en bloc perdrait la trace des
            // premiers envois et les referait au passage suivant.
            $this->em->flush();
            ++$mises;
        }

        $io->success(sprintf('%d publication(s) datee(s) mise(s) en file.', $mises));

        return Command::SUCCESS;
    }
}
