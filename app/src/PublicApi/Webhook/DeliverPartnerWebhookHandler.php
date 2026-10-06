<?php

declare(strict_types=1);

namespace App\PublicApi\Webhook;

use App\PublicApi\Entity\PartnerWebhookDelivery;
use App\PublicApi\Enum\DeliveryStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
 *     (une minute plus tard) — il reste en base et repart à la réouverture, sans brûler de tentative.
 *     Au-delà de 24 heures, la livraison EXPIRE (`abandoned`) au lieu de tourner sans fin.
 *  2. **Consentement REVÉRIFIÉ** (`PartnerConsent`) : un accord `events:subscribe` retiré entre deux
 *     réessais arrête la livraison — elle est fermée `abandoned`, avec sa raison.
 *  3. **Envoi signé** (`WebhookSender`, anti-SSRF épinglé). En échec, l'exception rend la main à
 *     Messenger, qui réessaie selon le transport `async`.
 *
 * ⚠ **LE PLAFOND EST COMPTÉ EN BASE, PAS PAR MESSENGER.** `MAX_ATTEMPTS` ne dépend pas du `max_retries`
 * de messenger.yaml : si Messenger abandonne avant (message parti au transport `failed`), la livraison
 * reste `pending` et `public-api:webhooks:requeue` la remet en file ; à `MAX_ATTEMPTS`, elle passe
 * `failed` et le handler ne lève plus. Toute erreur — y compris AVANT l'envoi (coffre illisible, base
 * indisponible) — compte une tentative : sans cela, une livraison réessaierait sans fin, muette.
 *
 * ⚠ Aucun message d'erreur ne porte l'URL (elle peut contenir un jeton) : voir `WebhookSender`.
 */
#[AsMessageHandler]
final class DeliverPartnerWebhookHandler
{
    public const MAX_ATTEMPTS = 6;

    public const CLOSED_DELAY_MS = 60_000;

    public const CLOSED_EXPIRY_HOURS = 24;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerConsent $consent,
        private readonly PartnerWebhookCipher $cipher,
        private readonly WebhookSender $sender,
        private readonly MessageBusInterface $bus,
        #[Autowire(env: 'bool:PARTNER_WEBHOOKS_ENABLED')] private readonly bool $enabled,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(DeliverPartnerWebhook $message): void
    {
        $delivery = Uuid::isValid($message->deliveryId) ? $this->em->find(PartnerWebhookDelivery::class, Uuid::fromString($message->deliveryId)) : null;
        if (!$delivery instanceof PartnerWebhookDelivery || DeliveryStatus::Pending !== $delivery->getStatus()) {
            return;
        }

        if (!$this->enabled) {
            $this->holdWhileClosed($delivery, $message);

            return;
        }

        try {
            if (!$this->consent->allows($delivery->getSubscription(), $delivery->getEtablissement())) {
                $delivery->abandon('Livraison arrêtée : l’établissement a retiré l’accord « events:subscribe », ou l’abonnement est coupé.');
                $this->em->flush();

                return;
            }

            $subscription = $delivery->getSubscription();
            $url = $this->cipher->decrypt($subscription->getEncryptedUrl());
            $secret = $this->cipher->decrypt($subscription->getEncryptedSecret());
            $result = null === $url || null === $secret
                ? ['ok' => false, 'error' => 'URL ou secret illisible : la clé PARTNER_WEBHOOK_KEY a-t-elle changé ?']
                : $this->sender->send($url, $secret, $delivery->getBody(), (string) $delivery->getEventId());
        } catch (\Throwable $e) {
            // Erreur AVANT ou PENDANT la préparation de l'envoi : la tentative compte. Seule la classe de
            // l'exception est gardée — son message pourrait porter l'URL.
            $result = ['ok' => false, 'error' => sprintf('Erreur interne avant l’envoi (%s).', (new \ReflectionClass($e))->getShortName())];
        }

        if ($result['ok']) {
            $delivery->recordAttempt(DeliveryStatus::Delivered, null);
            $this->em->flush();

            return;
        }

        $final = $delivery->getAttempts() + 1 >= self::MAX_ATTEMPTS;
        $delivery->recordAttempt($final ? DeliveryStatus::Failed : DeliveryStatus::Pending, $result['error']);
        $delivery->markQueued();
        $this->em->flush();

        if (!$final) {
            throw new \RuntimeException(sprintf('Webhook partenaire non livré (tentative %d) : %s', $delivery->getAttempts(), $result['error']));
        }
    }

    private function holdWhileClosed(PartnerWebhookDelivery $delivery, DeliverPartnerWebhook $message): void
    {
        if ($delivery->getCreatedAt() < new \DateTimeImmutable(sprintf('-%d hours', self::CLOSED_EXPIRY_HOURS))) {
            $delivery->abandon(sprintf('Livraison expirée : l’interrupteur PARTNER_WEBHOOKS_ENABLED est resté fermé plus de %d heures.', self::CLOSED_EXPIRY_HOURS));
            $this->em->flush();

            return;
        }

        $this->bus->dispatch($message, [new DelayStamp(self::CLOSED_DELAY_MS)]);
        $delivery->markQueued();
        $this->em->flush();
        $this->logger->debug('public_api.webhook.held_while_closed', ['delivery' => $message->deliveryId]);
    }
}
