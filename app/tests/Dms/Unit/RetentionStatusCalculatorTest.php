<?php

declare(strict_types=1);

namespace App\Tests\Dms\Unit;

use App\Dms\Enum\RetentionStatus;
use App\Dms\Service\RetentionStatusCalculator;
use PHPUnit\Framework\TestCase;

/** RG-DMS-12, CA-6 : statut de rétention dérivé, jamais persisté. */
final class RetentionStatusCalculatorTest extends TestCase
{
    public function testNullDonneNone(): void
    {
        $calculator = new RetentionStatusCalculator();
        self::assertSame(RetentionStatus::None, $calculator->statusFor(null));
    }

    public function testDateFutureDonneActive(): void
    {
        $calculator = new RetentionStatusCalculator();
        $futur = new \DateTimeImmutable('+30 days');
        self::assertSame(RetentionStatus::Active, $calculator->statusFor($futur));
    }

    public function testDatePasseeDonneExpired(): void
    {
        $calculator = new RetentionStatusCalculator();
        $passe = new \DateTimeImmutable('-1 day');
        self::assertSame(RetentionStatus::Expired, $calculator->statusFor($passe));
    }

    public function testAujourdHuiDonneExpired(): void
    {
        // « dépassé » inclut le jour même (pas strictement futur) — cohérent RG-DMS-12/CA-6.
        $calculator = new RetentionStatusCalculator();
        $aujourdHui = new \DateTimeImmutable('today');
        self::assertSame(RetentionStatus::Expired, $calculator->statusFor($aujourdHui));
    }
}
