<?php

declare(strict_types=1);

namespace App\PublicApi\Webhook;

use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\PublicApi\Entity\PartnerWebhookDelivery;
use App\PublicApi\Entity\PartnerWebhookSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Transforme un événement du catalogue partenaire en livraisons, une par abonnement concerné.
 *
 * ⚠ **SEULEMENT VERS LES APPLICATIONS QUE L'ÉTABLISSEMENT DE L'ÉVÉNEMENT A AUTORISÉES** (`events:subscribe`,
 * `PartnerConsent`). Le consentement est vérifié ici, puis REVÉRIFIÉ à chaque tentative par
 * `DeliverPartnerWebhookHandler` : un retrait entre deux réessais arrête la livraison.
 *
 * ⚠ **DIFFÉRÉ À LA FIN DE LA REQUÊTE, DE LA COMMANDE OU DU MESSAGE** — même raison que
 * `ForwardDomainEvents` : un événement est souvent publié au milieu de la transaction de l'émetteur, et
 * un `flush()` ici écrirait ses changements à moitié faits. Une panne ici ne casse jamais l'émetteur :
 * elle est journalisée.
 */
final class PartnerWebhookFanOut implements EventSubscriberInterface
{
    /** @var list<DomainEvent> */
    private array $pending = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerEventCatalog $catalog,
        private readonly PartnerConsent $consent,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        $events = array_fill_keys(PartnerEventCatalog::EVENTS, 'collect');
        $events[KernelEvents::TERMINATE] = 'flush';
        $events[ConsoleEvents::TERMINATE] = 'flush';
        $events[WorkerMessageHandledEvent::class] = 'flush';

        return $events;
    }

    public function collect(DomainEvent $event): void
    {
        $this->pending[] = $event;
    }

    public function flush(): void
    {
        if ([] === $this->pending) {
            return;
        }
        $events = $this->pending;
        $this->pending = [];

        try {
            /** @var list<PartnerWebhookSubscription> $subscriptions */
            $subscriptions = $this->em->getRepository(PartnerWebhookSubscription::class)->findBy(['active' => true]);
            $created = [];
            foreach ($events as $event) {
                $establishment = $this->em->find(Etablissement::class, $event->tenant->establishmentId);
                if (!$establishment instanceof Etablissement) {
                    continue;
                }
                $eventId = Uuid::v7();
                foreach ($subscriptions as $subscription) {
                    if (!$subscription->listensTo($event->name->value) || !$this->consent->allows($subscription, $establishment)) {
                        continue;
                    }
                    $delivery = new PartnerWebhookDelivery($subscription, $establishment, $eventId, $event->name->value, $this->envelope($event, $eventId, $subscription));
                    $this->em->persist($delivery);
                    $created[] = $delivery;
                }
            }
            $this->em->flush();

            foreach ($created as $delivery) {
                $this->bus->dispatch(new DeliverPartnerWebhook((string) $delivery->getId()));
            }
        } catch (\Throwable $e) {
            $this->logger->error('public_api.webhook.fan_out_failed', ['exception' => $e]);
        }
    }

    private function envelope(DomainEvent $event, Uuid $eventId, PartnerWebhookSubscription $subscription): string
    {
        $application = $subscription->getApplication() ?? throw new \LogicException('Abonnement sans application.');

        return json_encode([
            'id' => (string) $eventId,
            'type' => $event->name->value,
            'version' => PartnerEventCatalog::VERSION,
            'occurredAt' => $event->occurredAt->format(\DATE_ATOM),
            'establishmentId' => $event->tenant->establishmentId->toRfc4122(),
            'data' => $this->catalog->data($event, $application->getId()),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
