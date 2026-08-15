<?php

declare(strict_types=1);

namespace App\Tests\Sepa\Unit;

use App\Sepa\Adapter\RetourSepaStubAdapter;
use App\Sepa\Dto\RetourSepaDto;
use PHPUnit\Framework\TestCase;

/** Stub de retours SEPA (§4/§9 du plan) : `injecterRetourDeTest()` relevé une seule fois puis vidé. */
final class RetourSepaStubAdapterTest extends TestCase
{
    public function testInjecterRetourDeTestEstRelevePuisVide(): void
    {
        $adapter = new RetourSepaStubAdapter();
        $retour = new RetourSepaDto('EX00000000000001', 'RUM-TEST', 'AM04', 'Fonds insuffisants', 3990, new \DateTimeImmutable());

        self::assertSame([], $adapter->relever(new \DateTimeImmutable('-1 day')));

        $adapter->injecterRetourDeTest($retour);
        $releves = $adapter->relever(new \DateTimeImmutable('-1 day'));
        self::assertCount(1, $releves);
        self::assertSame($retour, $releves[0]);

        self::assertSame([], $adapter->relever(new \DateTimeImmutable('-1 day')));
    }
}
