<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Unit;

use App\Boutique\Controller\OnlinePaymentNotificationController;
use App\Boutique\Paiement\OnlinePaymentSettlement;
use App\Boutique\Paiement\SelecteurPaiementEnLigne;
use App\Boutique\Paiement\Stripe\StripeCheckoutAdapter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;

/**
 * The refusal path of the notification controller, with a spy logger: what is logged on a rejected
 * signature (G-3, G-4, G-8). The entity manager and the settlement are doubles that fail on any use:
 * a refusal must not reach them.
 */
final class OnlinePaymentNotificationControllerTest extends TestCase
{
    private const KEY = 'sk_test_' . 'FAUSSECLEDETEST0000';

    /** @return array{0: OnlinePaymentNotificationController, 1: object} */
    private function controller(?string $webhookSecret): array
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{0: mixed, 1: string, 2: array<string, mixed>}> */
            public array $entries = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->entries[] = [$level, (string) $message, $context];
            }
        };
        $adapter = new StripeCheckoutAdapter(new MockHttpClient(), $logger, self::KEY, null, $webhookSecret);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method(self::anything());
        $settlement = (new \ReflectionClass(OnlinePaymentSettlement::class))->newInstanceWithoutConstructor();

        return [new OnlinePaymentNotificationController(new SelecteurPaiementEnLigne([$adapter]), $settlement, $em, $logger), $logger];
    }

    private function dump(object $logger): string
    {
        return json_encode($logger->entries, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    public function testARefusedSignatureLogsOnlyTheProviderAndTheReason(): void
    {
        $secret = 'whsec_' . bin2hex(random_bytes(16));
        [$controller, $logger] = $this->controller($secret);
        $body = '{"type":"checkout.session.completed","data":{"object":{"id":"cs_test_corps_sensible"}}}';
        $header = 't=' . time() . ',v1=' . str_repeat('b', 64);

        $response = $controller('stripe', new Request(content: $body, server: ['HTTP_STRIPE_SIGNATURE' => $header]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('{"statut":"refuse"}', $response->getContent());
        self::assertCount(1, $logger->entries);
        [$level, $message, $context] = $logger->entries[0];
        self::assertSame('warning', $level);
        self::assertSame('Notification de paiement refusée (signature)', $message);
        self::assertSame(['prestataire' => 'stripe', 'motif' => 'invalid'], $context);
        $logged = $this->dump($logger);
        foreach ([$secret, $header, $body, 'cs_test_corps_sensible', str_repeat('b', 64)] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $logged);
        }
    }

    public function testAMissingWebhookSecretIsLoggedAsSuchWithoutNamingAProvider(): void
    {
        [$controller, $logger] = $this->controller(null);

        $response = $controller('stripe', new Request(content: '{}', server: ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=' . str_repeat('c', 64)]));

        self::assertSame(400, $response->getStatusCode());
        self::assertCount(1, $logger->entries);
        [$level, $message, $context] = $logger->entries[0];
        self::assertSame('warning', $level);
        self::assertSame('Notification de paiement refusée : secret de signature des notifications non configuré', $message);
        self::assertStringNotContainsStringIgnoringCase('stripe', $message);
        self::assertSame(['prestataire' => 'stripe', 'motif' => 'not_configured'], $context);
        self::assertStringNotContainsString(str_repeat('c', 64), $this->dump($logger));
    }
}
