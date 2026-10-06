<?php

declare(strict_types=1);

namespace App\PublicApi\Webhook;

use App\PublicApi\Entity\PartnerWebhookDelivery;
use App\PublicApi\Enum\DeliveryStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Une tentative de livraison d'un webhook partenaire.
 *
 * Dans l'ordre, et chaque étape a sa raison :
 *  1. **Interrupteur** `PARTNER_WEBHOOKS_ENABLED` fermé : rien ne part, et le message est REMIS en file
 *     (une minute plus tard) — il reste en base et repart à la réouverture, sans brûler de réessai.
 *  2. **Consentement REVÉRIFIÉ** (`PartnerConsent`) : un accord `events:subscribe` retiré entre deux
 *     réessais arrête la livraison — elle est fermée `abandoned`, avec sa raison.
 *  3. **Envoi signé** (`WebhookSender`, anti-SSRF épinglé). En échec, l'exception rend la main à
 *     Messenger, qui réessaie selon le transport `async` (5 réessais, délai ×3) ; à la 6ᵉ tentative,
 *     l'échec est DÉFINITIF (`failed`), tracé et visible côté éditeur — on ne lève plus.
 */
#[AsMessageHandler]
final class DeliverPartnerWebhookHandler
{
    /** 1 envoi + les 5 réessais du transport `async` (messenger.yaml). */
    public const MAX_ATTEMPTS = 6;

    public const CLOSED_DELAY_MS = 60_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerConsent $consent,
        private readonly PartnerWebhookCipher $cipher,
        private readonly WebhookSender $sender,
        private readonly MessageBusInterface $bus,
        #[Autowire(env: 'bool:PARTNER_WEBHOOKS_ENABLED')] private readonly bool $enabled,
    ) {
    }

    public function __invoke(DeliverPartnerWebhook $message): void
    {
        $delivery = Uuid::isValid($message->deliveryId) ? $this->em->find(PartnerWebhookDelivery::class, Uuid::fromString($message->deliveryId)) : null;
        if (!$delivery instanceof PartnerWebhookDelivery || DeliveryStatus::Pending !== $delivery->getStatus()) {
            return;
        }

        if (!$this->enabled) {
            $this->bus->dispatch($message, [new DelayStamp(self::CLOSED_DELAY_MS)]);

            return;
        }

        $subscription = $delivery->getSubscription();
        if (!$this->consent->allows($subscription, $delivery->getEtablissement())) {
            $delivery->abandon('Livraison arrêtée : l’établissement a retiré l’accord « events:subscribe », ou l’abonnement est coupé.');
            $this->em->flush();

            return;
        }

        $url = $this->cipher->decrypt($subscription->getEncryptedUrl());
        $secret = $this->cipher->decrypt($subscription->getEncryptedSecret());
        $result = null === $url || null === $secret
            ? ['ok' => false, 'error' => 'URL ou secret illisible : la clé PARTNER_WEBHOOK_KEY a-t-elle changé ?']
            : $this->sender->send($url, $secret, $delivery->getBody(), (string) $delivery->getEventId());

        if ($result['ok']) {
            $delivery->recordAttempt(DeliveryStatus::Delivered, null);
            $this->em->flush();

            return;
        }

        $final = $delivery->getAttempts() + 1 >= self::MAX_ATTEMPTS;
        $delivery->recordAttempt($final ? DeliveryStatus::Failed : DeliveryStatus::Pending, $result['error']);
        $this->em->flush();

        if (!$final) {
            throw new \RuntimeException(sprintf('Webhook partenaire non livré (tentative %d) : %s', $delivery->getAttempts(), $result['error']));
        }
    }
}
