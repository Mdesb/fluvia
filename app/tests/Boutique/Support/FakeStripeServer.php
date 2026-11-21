<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * THE TEST DOUBLE OF THE STRIPE API (checkout sessions only) — a server, not a stub of our adapter.
 *
 * Receives the requests our `StripeCheckoutAdapter` really sends (through a `MockHttpClient`), keeps the
 * sessions it was asked to open and answers `GET /v1/checkout/sessions/{id}` with the outcome the test
 * decided. Every call is recorded: a refused notification must leave `calls()` empty.
 *
 * State is static: API tests reboot the kernel, the double must survive it. `reset()` in `setUp()`.
 * Inert until a test sets STRIPE_SECRET_KEY (same pattern as `FakePayFipServer`).
 */
final class FakeStripeServer
{
    /** @var array<string, int> session id => amount in cents */
    private static array $sessions = [];
    /** @var array<string, bool> session id => paid */
    private static array $paid = [];
    /** @var list<string> "METHOD /path" */
    private static array $calls = [];

    public static function reset(): void
    {
        self::$sessions = [];
        self::$paid = [];
        self::$calls = [];
    }

    /** The buyer finished paying on the hosted page. */
    public static function markPaid(string $sessionId): void
    {
        self::$paid[$sessionId] = true;
    }

    /** @return list<string> every call received, in order */
    public static function calls(): array
    {
        return self::$calls;
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        self::$calls[] = $method . ' ' . $path;

        if ($method === 'POST' && $path === '/v1/checkout/sessions') {
            parse_str((string) ($options['body'] ?? ''), $fields);
            $id = 'cs_test_' . bin2hex(random_bytes(12));
            self::$sessions[$id] = (int) ($fields['line_items'][0]['price_data']['unit_amount'] ?? 0);

            return self::json(['id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/' . $id]);
        }

        if ($method === 'GET' && preg_match('#^/v1/checkout/sessions/(cs_[A-Za-z0-9_]+)$#', $path, $m) === 1) {
            $id = $m[1];
            if (!isset(self::$sessions[$id])) {
                return self::json(['error' => ['message' => 'No such checkout.session']], 404);
            }
            $paid = self::$paid[$id] ?? false;

            return self::json([
                'id' => $id,
                'payment_status' => $paid ? 'paid' : 'unpaid',
                'status' => $paid ? 'complete' : 'open',
                'amount_total' => self::$sessions[$id],
                'currency' => 'eur',
            ]);
        }

        return self::json(['error' => ['message' => 'Unrecognized request URL']], 404);
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body, int $code = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $code, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
