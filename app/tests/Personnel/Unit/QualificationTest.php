<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Unit;

use App\Personnel\Entity\Qualification;
use App\Personnel\Enum\TypeQualification;
use PHPUnit\Framework\TestCase;

/**
 * Statut dérivé (non persisté, décision n°10 du plan) d'une Qualification (RG-PERSO-02, CA-3).
 */
final class QualificationTest extends TestCase
{
    public function testStatutExpireApresDateValidite(): void
    {
        $qualification = new Qualification();
        $qualification->setType(TypeQualification::Mns)->setDateValidite(new \DateTimeImmutable('2020-01-01'));

        self::assertFalse($qualification->estValideA(new \DateTimeImmutable('2026-01-01')));
        self::assertSame('expiree', $qualification->getStatut());
    }

    public function testStatutValideAvantDateValidite(): void
    {
        $qualification = new Qualification();
        $qualification->setType(TypeQualification::Bnssa)->setDateValidite(new \DateTimeImmutable('2030-01-01'));

        self::assertTrue($qualification->estValideA(new \DateTimeImmutable('2026-01-01')));
        self::assertSame('valide', $qualification->getStatut());
    }
}
