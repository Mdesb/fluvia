<?php

declare(strict_types=1);

namespace App\PublicApi\Webhook;

use App\PublicApi\Entity\PartnerApplication;
use App\PublicApi\Entity\PartnerWebhookDelivery;
use App\PublicApi\Entity\PartnerWebhookSubscription;
use App\PublicApi\Enum\DeliveryStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce que l'éditeur voit de l'abonnement d'une application : l'hôte (jamais l'URL ni le secret), les
 * événements, et l'état des livraisons — ce qui attend depuis plus de 15 minutes et les échecs
 * définitifs, les plus récents d'abord. Un webhook qui échoue en silence est un webhook qu'on croit
 * branché.
 */
final class PartnerWebhookOverview
{
    public const STALE_MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function of(PartnerApplication $application): ?array
    {
        $subscription = $this->em->getRepository(PartnerWebhookSubscription::class)->findOneBy(['application' => $application]);
        if (!$subscription instanceof PartnerWebhookSubscription) {
            return null;
        }

        $deliveries = $this->em->getRepository(PartnerWebhookDelivery::class);
        $stale = (int) $deliveries->createQueryBuilder('d')->select('COUNT(d.id)')
            ->andWhere('d.subscription = :s')->andWhere('d.status = :pending')->andWhere('d.createdAt < :limit')
            ->setParameter('s', $subscription->getId(), 'uuid')
            ->setParameter('pending', DeliveryStatus::Pending->value)
            ->setParameter('limit', new \DateTimeImmutable(sprintf('-%d minutes', self::STALE_MINUTES)), 'datetime_immutable')
            ->getQuery()->getSingleScalarResult();

        /** @var list<PartnerWebhookDelivery> $failed */
        $failed = $deliveries->findBy(['subscription' => $subscription, 'status' => DeliveryStatus::Failed], ['createdAt' => 'DESC'], 10);

        return [
            'host' => $subscription->getUrlHost(),
            'events' => $subscription->getEvents(),
            'active' => $subscription->isActive(),
            'secretRotatedAt' => $subscription->getSecretRotatedAt()->format(\DATE_ATOM),
            'pendingOver15Minutes' => $stale,
            'failedDeliveries' => array_map(static fn (PartnerWebhookDelivery $d): array => [
                'eventId' => (string) $d->getEventId(),
                'eventType' => $d->getEventType(),
                'establishment' => (string) $d->getEtablissement()->getId(),
                'attempts' => $d->getAttempts(),
                'lastError' => $d->getLastError(),
                'lastAttemptAt' => $d->getLastAttemptAt()?->format(\DATE_ATOM),
            ], $failed),
        ];
    }
}
