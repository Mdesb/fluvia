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
 * It models ACCOUNTS (D3-B of #365): `registerKey($key, $account)` says which account a key opens (two keys of
 * the same account = a key rotation). A session belongs to the account of the key that created it; any other
 * account gets a 404, an unknown key a 401 whose `message` quotes the WHOLE key (what must never reach a log).
 * With no key registered (the legacy tests), every key opens the same single account.
 *
 * State is static: API tests reboot the kernel, the double must survive it. `reset()` in `setUp()`.
 * Inert until a test sets STRIPE_SECRET_KEY (same pattern as `FakePayFipServer`).
 */
final class FakeStripeServer
{
    private const LEGACY_ACCOUNT = '*';

    /** @var array<string, int> session id => amount in cents */
    private static array $sessions = [];
    /** @var array<string, string> session id => account that opened it */
    private static array $owners = [];
    /** @var array<string, string> key => account */
    private static array $keys = [];
    /** @var list<array{call: string, key: string}> */
    private static array $keyedCalls = [];
    private static ?int $rejectWith = null;
    /** @var array<string, bool> session id => paid */
    private static array $paid = [];
    /** @var list<string> "METHOD /path" */
    private static array $calls = [];

    public static function reset(): void
    {
        self::$sessions = [];
        self::$owners = [];
        self::$keys = [];
        self::$keyedCalls = [];
        self::$rejectWith = null;
        self::$paid = [];
        self::$calls = [];
    }

    /** The key opens this account. Two keys of the same account model a rotation. */
    public static function registerKey(string $key, string $account): void
    {
        self::$keys[$key] = $account;
    }

    /** Every call answers this HTTP status with an error whose message quotes the key it received. */
    public static function rejectEverythingWith(int $status): void
    {
        self::$rejectWith = $status;
    }

    /** @return list<array{call: string, key: string}> every call with the key it used, in order */
    public static function keyedCalls(): array
    {
        return self::$keyedCalls;
    }

    /** @return list<string> the keys the calls used, in order */
    public static function keysUsed(): array
    {
        return array_column(self::$keyedCalls, 'key');
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
        // `MockHttpClient` normalizes `auth_bearer` into an `Authorization: Bearer …` header line.
        $key = preg_replace('/^Authorization:\s*Bearer\s+/i', '', (string) ($options['normalized_headers']['authorization'][0] ?? '')) ?? '';
        self::$keyedCalls[] = ['call' => $method . ' ' . $path, 'key' => $key];

        if (self::$rejectWith !== null) {
            return self::json(['error' => ['type' => 'invalid_request_error', 'code' => 'api_key_invalid', 'message' => 'Invalid API Key provided: ' . $key]], self::$rejectWith);
        }
        if (self::$keys === []) {
            $account = self::LEGACY_ACCOUNT;
        } elseif (isset(self::$keys[$key])) {
            $account = self::$keys[$key];
        } else {
            return self::json(['error' => ['type' => 'invalid_request_error', 'code' => 'api_key_invalid', 'message' => 'Invalid API Key provided: ' . $key]], 401);
        }

        if ($method === 'POST' && $path === '/v1/checkout/sessions') {
            parse_str((string) ($options['body'] ?? ''), $fields);
            $id = 'cs_test_' . bin2hex(random_bytes(12));
            self::$sessions[$id] = (int) ($fields['line_items'][0]['price_data']['unit_amount'] ?? 0);
            self::$owners[$id] = $account;

            return self::json(['id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/' . $id]);
        }

        if ($method === 'GET' && preg_match('#^/v1/checkout/sessions/(cs_[A-Za-z0-9_]+)$#', $path, $m) === 1) {
            $id = $m[1];
            // A session only exists in the account that opened it: another account's key gets a 404.
            if (!isset(self::$sessions[$id]) || self::$owners[$id] !== $account) {
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
