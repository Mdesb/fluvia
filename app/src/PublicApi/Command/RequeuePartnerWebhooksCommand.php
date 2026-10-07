<?php

declare(strict_types=1);

namespace App\PublicApi\Command;

use App\PublicApi\Entity\PartnerWebhookDelivery;
use App\PublicApi\Enum\DeliveryStatus;
use App\PublicApi\Webhook\DeliverPartnerWebhook;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Remet en file les livraisons de webhook partenaire `pending` qu'aucun message ne porte plus.
 *
 * Trois façons d'en arriver là, et aucune ne fait de bruit : la mise en file a échoué juste après
 * l'écriture de la livraison (ou le processus est mort entre les deux) ; Messenger a abandonné ses
 * réessais avant le plafond propre de `DeliverPartnerWebhookHandler` ; un message s'est perdu.
 *
 * Seuil : 30 minutes depuis la dernière mise en file — au-delà du plus long délai de réessai du transport
 * `async` (810 s au 5ᵉ réessai), pour ne pas doubler un réessai encore prévu. Un doublon éventuel reste
 * sans effet chez le partenaire : même `Idempotency-Key`, et le handler ignore ce qui n'est plus `pending`.
 */
#[AsCommand(
    name: 'public-api:webhooks:requeue',
    description: 'Remet en file les livraisons de webhook partenaire en attente qu’aucun message ne porte depuis 30 minutes.',
)]
final class RequeuePartnerWebhooksCommand extends Command
{
    public const STALE_MINUTES = 30;

    private const BATCH = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = new \DateTimeImmutable(sprintf('-%d minutes', self::STALE_MINUTES));

        /** @var list<PartnerWebhookDelivery> $stale */
        $stale = $this->em->getRepository(PartnerWebhookDelivery::class)->createQueryBuilder('d')
            ->andWhere('d.status = :pending')->setParameter('pending', DeliveryStatus::Pending->value)
            ->andWhere('(d.queuedAt IS NULL AND d.createdAt < :limit) OR d.queuedAt < :limit')
            ->setParameter('limit', $limit, 'datetime_immutable')
            ->orderBy('d.createdAt', 'ASC')
            ->setMaxResults(self::BATCH)
            ->getQuery()->getResult();

        foreach ($stale as $delivery) {
            $this->bus->dispatch(new DeliverPartnerWebhook((string) $delivery->getId()));
            $delivery->markQueued();
        }
        $this->em->flush();

        $output->writeln(sprintf('%d livraison(s) de webhook partenaire remise(s) en file.', \count($stale)));

        return Command::SUCCESS;
    }
}
