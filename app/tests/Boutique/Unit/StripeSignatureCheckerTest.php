<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Unit;

use App\Boutique\Paiement\InvalidNotificationSignatureException;
use App\Boutique\Paiement\NotificationRejection;
use App\Boutique\Paiement\Stripe\StripeSignatureChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Stripe signature check: pure, explicit clock, no mock. */
final class StripeSignatureCheckerTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const BODY = '{"type":"checkout.session.completed","data":{"object":{"id":"cs_test_abc"}}}';

    private string $secret;

    protected function setUp(): void
    {
        $this->secret = 'whsec_' . bin2hex(random_bytes(16));
    }

    private function sign(string $body, int $t, ?string $secret = null): string
    {
        return hash_hmac('sha256', $t . '.' . $body, $secret ?? $this->secret);
    }

    private function expectRejection(NotificationRejection $reason, string $header, string $body = self::BODY, ?string $secret = null, int $now = self::NOW): void
    {
        try {
            (new StripeSignatureChecker())->verify($header, $body, $secret ?? $this->secret, $now);
            self::fail('a rejection was expected');
        } catch (InvalidNotificationSignatureException $e) {
            self::assertSame($reason, $e->reason);
            self::assertStringNotContainsString($this->secret, $e->getMessage());
            self::assertTrue($header === '' || !str_contains($e->getMessage(), $header));
            self::assertStringNotContainsString($body, $e->getMessage());
        }
    }

    public function testValidSignatureIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        (new StripeSignatureChecker())->verify('t=' . self::NOW . ',v1=' . $this->sign(self::BODY, self::NOW), self::BODY, $this->secret, self::NOW);
    }

    public function testOneGoodV1AmongSeveralAndIgnoredEntriesIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        $header = 'v0=' . $this->sign(self::BODY, self::NOW) . ',v1=' . str_repeat('a', 64) . ',unknown=x,t=' . self::NOW . ',v1=' . $this->sign(self::BODY, self::NOW);
        (new StripeSignatureChecker())->verify($header, self::BODY, $this->secret, self::NOW);
    }

    public function testBodyModifiedByOneByteIsInvalid(): void
    {
        $this->expectRejection(NotificationRejection::Invalid, 't=' . self::NOW . ',v1=' . $this->sign(self::BODY, self::NOW), self::BODY . ' ');
    }

    public function testWrongSecretIsInvalid(): void
    {
        $this->expectRejection(NotificationRejection::Invalid, 't=' . self::NOW . ',v1=' . $this->sign(self::BODY, self::NOW, 'whsec_' . bin2hex(random_bytes(16))));
    }

    public function testV0AloneIsNotAccepted(): void
    {
        $this->expectRejection(NotificationRejection::Malformed, 't=' . self::NOW . ',v0=' . $this->sign(self::BODY, self::NOW));
    }

    public function testMissingHeader(): void
    {
        $this->expectRejection(NotificationRejection::Missing, '');
    }

    /** @return iterable<string, array{string}> */
    public static function malformedHeaders(): iterable
    {
        yield 'garbage' => ['nimporte quoi'];
        yield 'no timestamp' => ['v1=' . str_repeat('a', 64)];
        yield 'no v1' => ['t=' . self::NOW];
        yield 'non numeric t' => ['t=abc,v1=' . str_repeat('a', 64)];
        yield 'negative t' => ['t=-5,v1=' . str_repeat('a', 64)];
        yield 'float t' => ['t=1800000000.5,v1=' . str_repeat('a', 64)];
        yield 'uppercase V1 key' => ['t=' . self::NOW . ',V1=' . str_repeat('a', 64)];
        yield 'inner space in t' => ['t=1800 000000,v1=' . str_repeat('a', 64)];
        yield 'two t' => ['t=' . self::NOW . ',t=' . (self::NOW + 1) . ',v1=' . str_repeat('a', 64)];
    }

    #[DataProvider('malformedHeaders')]
    public function testMalformedHeader(string $header): void
    {
        $this->expectRejection(NotificationRejection::Malformed, $header);
    }

    public function testToleranceWindowIsSymmetric(): void
    {
        $verifier = new StripeSignatureChecker();
        foreach ([-299, 0, 299, 300, -300] as $offset) {
            $t = self::NOW + $offset;
            $verifier->verify('t=' . $t . ',v1=' . $this->sign(self::BODY, $t), self::BODY, $this->secret, self::NOW);
        }
        foreach ([-301, 301] as $offset) {
            $t = self::NOW + $offset;
            $this->expectRejection(NotificationRejection::OutOfWindow, 't=' . $t . ',v1=' . $this->sign(self::BODY, $t));
        }
        self::assertTrue(true);
    }

    public function testEmptySecretIsNotConfiguredEvenWithAValidLookingHeader(): void
    {
        $this->expectRejection(NotificationRejection::NotConfigured, 't=' . self::NOW . ',v1=' . $this->sign(self::BODY, self::NOW, ''), self::BODY, '');
    }

    /** Mutation guard: with an empty secret, the empty header must say NotConfigured, not Missing. */
    public function testEmptyHeaderAndEmptySecretIsNotConfigured(): void
    {
        $this->expectRejection(NotificationRejection::NotConfigured, '', self::BODY, '');
    }
}
