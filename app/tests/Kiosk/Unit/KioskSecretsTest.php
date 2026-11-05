<?php

declare(strict_types=1);

namespace App\Tests\Kiosk\Unit;

use App\Kiosk\Service\KioskSecrets;
use PHPUnit\Framework\TestCase;

final class KioskSecretsTest extends TestCase
{
    public function testAPairingCodeIsReadableAndSurvivesSloppyTyping(): void
    {
        $secrets = new KioskSecrets();
        $code = $secrets->newPairingCode();

        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code, 'Ni 0/O ni 1/I/L : on recopie sans hésiter.');
        self::assertSame($secrets->hashPairingCode($code), $secrets->hashPairingCode(strtolower(str_replace('-', ' ', $code))));
        self::assertNotSame($secrets->hashPairingCode($code), $secrets->hashPairingCode('AAAA-AAAA'));
    }

    public function testTheDeviceTokenIsKeptOnlyAsAHash(): void
    {
        $secrets = new KioskSecrets();
        $token = $secrets->newDeviceToken();

        self::assertSame(64, \strlen($token));
        self::assertNotSame($token, $secrets->hashDeviceToken($token));
        self::assertNotSame($secrets->newDeviceToken(), $token);
    }

    public function testTheExitCodeMatchesOnlyItself(): void
    {
        $secrets = new KioskSecrets();
        $hash = $secrets->hashExitCode('2468');

        self::assertTrue($secrets->exitCodeMatches('2468', $hash));
        self::assertFalse($secrets->exitCodeMatches('2469', $hash));
        self::assertFalse($secrets->exitCodeMatches('', $hash));
        self::assertFalse($secrets->exitCodeMatches('2468', null), 'Sans code posé, rien ne déverrouille.');
    }
}
