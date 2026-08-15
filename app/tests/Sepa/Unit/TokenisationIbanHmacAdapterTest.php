<?php

declare(strict_types=1);

namespace App\Tests\Sepa\Unit;

use App\Sepa\Adapter\TokenisationIbanHmacAdapter;
use PHPUnit\Framework\TestCase;

/** IBAN jamais en clair (§4 spec/plan-sepa) : token HMAC non réversible trivialement, 4 derniers exacts. */
final class TokenisationIbanHmacAdapterTest extends TestCase
{
    public function testTokeniserNeContientJamaisLibanEnClairEtExposeLes4Derniers(): void
    {
        $adapter = new TokenisationIbanHmacAdapter('cle-test-hmac');
        $iban = 'FR7630006000011234567890189';

        $token = $adapter->tokeniser($iban);

        self::assertSame('0189', $token->quatreDerniers);
        self::assertStringNotContainsString($iban, $token->token);
        self::assertStringNotContainsString('FR76', $token->token);
        self::assertSame(64, \strlen($token->token), 'HMAC-SHA256 hexadécimal = 64 caractères.');
    }

    public function testTokeniserEstDeterministePourLeMemeIbanEtLaMemeCle(): void
    {
        $adapter = new TokenisationIbanHmacAdapter('cle-test-hmac');
        $iban = 'FR7630006000011234567890189';

        self::assertSame($adapter->tokeniser($iban)->token, $adapter->tokeniser($iban)->token);
    }

    public function testTokeniserDiffereSelonLaCleDapplication(): void
    {
        $iban = 'FR7630006000011234567890189';
        $tokenA = (new TokenisationIbanHmacAdapter('cle-a'))->tokeniser($iban)->token;
        $tokenB = (new TokenisationIbanHmacAdapter('cle-b'))->tokeniser($iban)->token;

        self::assertNotSame($tokenA, $tokenB);
    }
}
