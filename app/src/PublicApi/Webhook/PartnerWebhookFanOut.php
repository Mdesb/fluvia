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
 *
 * ⚠ **CHAQUE ÉVÉNEMENT EST ISOLÉ** : une enveloppe qui ne se compose pas (donnée invalide) ne fait pas
 * perdre les événements des autres établissements. Et une livraison écrite dont la mise en file échoue
 * (ou dont le processus meurt entre les deux) reste `pending` sans date de mise en file :
 * `public-api:webhooks:requeue` la reprend.
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
        } catch (\Throwable $e) {
            $this->logger->error('public_api.webhook.fan_out_failed', ['exception' => $e]);

            return;
        }

        foreach ($events as $event) {
            try {
                $this->fanOut($event, $subscriptions);
            } catch (\Throwable $e) {
                // Le nom de l'événement et son établissement, pas sa charge : elle n'a rien à faire au journal.
                $this->logger->error('public_api.webhook.fan_out_failed', [
                    'event' => $event->name->value,
                    'establishment' => $event->tenant->establishmentId->toRfc4122(),
                    'error' => $e::class,
                ]);
            }
        }
    }

    /** @param list<PartnerWebhookSubscription> $subscriptions */
    private function fanOut(DomainEvent $event, array $subscriptions): void
    {
        $establishment = $this->em->find(Etablissement::class, $event->tenant->establishmentId);
        if (!$establishment instanceof Etablissement) {
            return;
        }

        // Toutes les enveloppes de l'événement d'abord : si l'une ne se compose pas, rien n'est écrit pour lui.
        $eventId = Uuid::v7();
        $created = [];
        foreach ($subscriptions as $subscription) {
            if ($subscription->listensTo($event->name->value) && $this->consent->allows($subscription, $establishment)) {
                $created[] = new PartnerWebhookDelivery($subscription, $establishment, $eventId, $event->name->value, $this->envelope($event, $eventId, $subscription));
            }
        }
        if ([] === $created) {
            return;
        }
        foreach ($created as $delivery) {
            $this->em->persist($delivery);
        }
        $this->em->flush();

        foreach ($created as $delivery) {
            $this->bus->dispatch(new DeliverPartnerWebhook((string) $delivery->getId()));
            $delivery->markQueued();
        }
        $this->em->flush();
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
