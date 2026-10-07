<?php

declare(strict_types=1);

namespace App\PublicApi\Command;

use App\PublicApi\Entity\PartnerWebhookDelivery;
use App\PublicApi\Enum\DeliveryStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * L'alerte minimale des webhooks partenaires (spec API partenaire v1, §3.5).
 *
 * Deux signaux, et chacun dit une panne différente :
 *  - des livraisons EN ATTENTE depuis plus de 15 minutes : le worker ne consomme plus, ou
 *    l'interrupteur `PARTNER_WEBHOOKS_ENABLED` est fermé ;
 *  - des échecs DÉFINITIFS depuis 24 heures : un abonné est en panne ou son URL est fausse.
 *
 * Elle ne fait que LIRE. Elle rend un code d'échec quand il y a quelque chose à voir : l'ordonnanceur
 * l'écrit alors « ÉCHEC » dans son journal et `platform:scheduler:run --status` le montre. Le détail par
 * application est dans la fiche « API partenaires » de l'éditeur.
 */
#[AsCommand(
    name: 'public-api:webhooks:alerter',
    description: 'Signale les webhooks partenaires en attente depuis plus de 15 minutes et les échecs définitifs des dernières 24 heures.',
)]
final class AlertPartnerWebhooksCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stale = $this->count(DeliveryStatus::Pending, 'd.createdAt < :limit', new \DateTimeImmutable('-15 minutes'));
        $failed = $this->count(DeliveryStatus::Failed, 'd.lastAttemptAt >= :limit', new \DateTimeImmutable('-24 hours'));

        if (0 === $stale && 0 === $failed) {
            $output->writeln('Webhooks partenaires : rien en attente depuis plus de 15 minutes, aucun échec définitif sur 24 heures.');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<error>Webhooks partenaires : %d livraison(s) en attente depuis plus de 15 minutes (worker arrêté ou interrupteur fermé ?), %d échec(s) définitif(s) sur 24 heures. Détail : administration éditeur › API partenaires.</error>',
            $stale,
            $failed,
        ));

        return Command::FAILURE;
    }

    private function count(DeliveryStatus $status, string $condition, \DateTimeImmutable $limit): int
    {
        return (int) $this->em->getRepository(PartnerWebhookDelivery::class)->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.status = :status')->setParameter('status', $status->value)
            ->andWhere($condition)->setParameter('limit', $limit, 'datetime_immutable')
            ->getQuery()->getSingleScalarResult();
    }
}
