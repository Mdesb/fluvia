<?php

declare(strict_types=1);

namespace App\PublicApi\Webhook;

use App\Audit\Service\JournalAudit;
use App\Organisation\Service\EditorTenantResolver;
use App\PublicApi\Entity\PartnerApplication;
use App\PublicApi\Entity\PartnerWebhookSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Les gestes de l'éditeur sur l'abonnement d'une application : configurer, régénérer le secret, couper.
 * Chacun est audité, rattaché à l'éditeur.
 *
 * ⚠ **LE SECRET N'EXISTE EN CLAIR QUE DANS LE RETOUR DE `configure()` (À LA CRÉATION) ET DE
 * `rotateSecret()`.** En base il est chiffré ; il n'est jamais relu par l'écran.
 */
final class PartnerWebhookManager
{
    public const ACTION_CONFIGURED = 'public_api.webhook.configured';
    public const ACTION_SECRET_ROTATED = 'public_api.webhook.secret_rotated';
    public const ACTION_DISABLED = 'public_api.webhook.disabled';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerWebhookCipher $cipher,
        private readonly JournalAudit $audit,
        private readonly EditorTenantResolver $editorTenant,
        private readonly WebhookDestinationGuard $guard,
    ) {
    }

    public function find(PartnerApplication $application): ?PartnerWebhookSubscription
    {
        return $this->em->getRepository(PartnerWebhookSubscription::class)->findOneBy(['application' => $application]);
    }

    /**
     * @param list<mixed> $events
     *
     * @return string|null le secret, seulement quand l'abonnement vient d'être créé
     */
    public function configure(PartnerApplication $application, string $url, array $events): ?string
    {
        $host = $this->guard->assertAcceptableUrl($url);
        $events = array_values(array_unique(array_filter($events, 'is_string')));
        if ([] === $events || [] !== array_diff($events, PartnerEventCatalog::EVENTS)) {
            throw new UnprocessableEntityHttpException(sprintf('Choisissez au moins un événement parmi : %s.', implode(', ', PartnerEventCatalog::EVENTS)));
        }

        $subscription = $this->find($application);
        $secret = null;
        if (null === $subscription) {
            $subscription = new PartnerWebhookSubscription($application);
            $secret = self::newSecret();
            $subscription->setEncryptedSecret($this->cipher->encrypt($secret));
            $this->em->persist($subscription);
        }
        $subscription->setUrl($this->cipher->encrypt($url), $host)->setEvents($events)->setActive(true);

        $this->audit->enregistrer(self::ACTION_CONFIGURED, 'PartnerWebhookSubscription', (string) $subscription->getId(), $this->editorTenant->resolveId())
            ->setValeurApres(['host' => $host, 'events' => $events]);
        $this->em->flush();

        return $secret;
    }

    public function rotateSecret(PartnerWebhookSubscription $subscription): string
    {
        $secret = self::newSecret();
        $subscription->setEncryptedSecret($this->cipher->encrypt($secret));
        $this->audit->enregistrer(self::ACTION_SECRET_ROTATED, 'PartnerWebhookSubscription', (string) $subscription->getId(), $this->editorTenant->resolveId());
        $this->em->flush();

        return $secret;
    }

    public function disable(PartnerWebhookSubscription $subscription): void
    {
        if (!$subscription->isActive()) {
            return;
        }
        $subscription->setActive(false);
        $this->audit->enregistrer(self::ACTION_DISABLED, 'PartnerWebhookSubscription', (string) $subscription->getId(), $this->editorTenant->resolveId());
        $this->em->flush();
    }

    private static function newSecret(): string
    {
        return 'whsec_'.bin2hex(random_bytes(32));
    }
}
