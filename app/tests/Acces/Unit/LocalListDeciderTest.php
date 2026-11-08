<?php

declare(strict_types=1);

namespace App\Tests\Acces\Unit;

use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Hardware\LocalListDecider;
use PHPUnit\Framework\TestCase;

/** Décision d'une borne déconnectée sur sa liste locale (#337). */
final class LocalListDeciderTest extends TestCase
{
    private const PORTE = '0192f0a0-0000-7000-8000-000000000001';

    /** @return array<string, mixed> */
    private function entree(array $surcharge = []): array
    {
        return $surcharge + [
            'identifiant' => '04A1B2C3',
            'revoque' => false,
            'portesEligibles' => [self::PORTE],
            'validiteDebut' => '2027-04-01T08:00:00+00:00',
            'validiteFin' => '2027-04-01T20:00:00+00:00',
            'compostagesRestants' => null,
            'headcount' => 1,
        ];
    }

    private function a(string $heure = '10:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-04-01T' . $heure . ':00+00:00');
    }

    public function testTitreValideOuvre(): void
    {
        $decision = (new LocalListDecider())->decide($this->entree(), self::PORTE, $this->a());

        self::assertTrue($decision->granted);
        self::assertSame(1, $decision->headcount);
    }

    public function testTitreRevoqueEstRefuse(): void
    {
        $decision = (new LocalListDecider())->decide($this->entree(['revoque' => true]), self::PORTE, $this->a());

        self::assertFalse($decision->granted);
        self::assertSame(CodeMotifRefus::SupportBloque->value, $decision->reasonCode);
    }

    public function testSupportAbsentDeLaListeEstRefuse(): void
    {
        self::assertSame(CodeMotifRefus::DroitInvalide->value, (new LocalListDecider())->decide(null, self::PORTE, $this->a())->reasonCode);
    }

    public function testPorteNonEligibleEstRefusee(): void
    {
        $decision = (new LocalListDecider())->decide($this->entree(), '0192f0a0-0000-7000-8000-000000000099', $this->a());

        self::assertSame(CodeMotifRefus::ZoneNonAutorisee->value, $decision->reasonCode);
    }

    public function testHorsValiditeEstRefuse(): void
    {
        self::assertSame(CodeMotifRefus::HorsMarge->value, (new LocalListDecider())->decide($this->entree(), self::PORTE, $this->a('21:00'))->reasonCode);
        self::assertSame(CodeMotifRefus::HorsMarge->value, (new LocalListDecider())->decide($this->entree(), self::PORTE, $this->a('07:59'))->reasonCode);
    }

    public function testTitreDeGroupeAccordeNRotations(): void
    {
        $decision = (new LocalListDecider())->decide($this->entree(['headcount' => 12]), self::PORTE, $this->a());

        self::assertTrue($decision->granted);
        self::assertSame(12, $decision->headcount);
    }

    public function testGroupeRefuseSiLaCarteNaPasUneEntreeParPersonne(): void
    {
        $decider = new LocalListDecider();

        self::assertFalse($decider->decide($this->entree(['headcount' => 5, 'compostagesRestants' => 4]), self::PORTE, $this->a())->granted);
        self::assertTrue($decider->decide($this->entree(['headcount' => 5, 'compostagesRestants' => 5]), self::PORTE, $this->a())->granted, 'Témoin : juste assez passe.');
    }
}
