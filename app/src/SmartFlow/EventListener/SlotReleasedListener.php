<?php

declare(strict_types=1);

namespace App\SmartFlow\EventListener;

use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\SmartFlow\Service\SlotWaitlistPromotionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Auto-consommé (RG-SF-06, plan-smart-flow.md T9) : à réception de `slot.released` (publié par
 * `SlotFreedListener` dans la même publication synchrone, D7 — le bus autorise la réentrance dans les
 * limites de `SymfonyEventBus::DEFAULT_MAX_DEPTH`), tente une promotion FIFO sur la liste d'attente
 * Smart Flow de la ressource concernée (`SlotWaitlistPromotionService`).
 *
 * Best-effort (D7) : jamais de propagation d'exception — une erreur de promotion ne doit pas remonter
 * jusqu'à l'émetteur d'origine (`AnnulerReservationProcessor`/`BasculerNoShowCommand`).
 */
final class SlotReleasedListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SlotWaitlistPromotionService $promotionService,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            'slot.released' => 'onSlotReleased',
        ];
    }

    public function onSlotReleased(DomainEvent $event): void
    {
        try {
            $this->handle($event);
        } catch (\Throwable $error) {
            $this->logger?->error('smart_flow.listener.failed', [
                'event' => $event->name->value,
                'reason' => $error->getMessage(),
            ]);
        }
    }

    private function handle(DomainEvent $event): void
    {
        $establishment = $this->em->getRepository(Etablissement::class)->find($event->tenant->establishmentId);
        if (!$establishment instanceof Etablissement) {
            return;
        }

        $slotId = $this->uuid($event->payload['slot'] ?? null);
        $resourceId = $this->uuid($event->payload['resource'] ?? null);
        if ($slotId === null || $resourceId === null) {
            return;
        }

        $this->promotionService->promoteNext($establishment, $resourceId, $slotId);
    }

    private function uuid(mixed $value): ?Uuid
    {
        return \is_string($value) && Uuid::isValid($value) ? Uuid::fromString($value) : null;
    }
}
