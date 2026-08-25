<?php

declare(strict_types=1);

namespace App\Tests\Stay\Unit;

use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Stay\Entity\Stay;
use App\Stay\Enum\StayResolutionReason;
use App\Stay\Service\OpenStayLookup;
use App\Stay\Service\OpenStayResolver;
use PHPUnit\Framework\TestCase;

/**
 * La règle de rattachement (ACT-3) : à quel séjour va une consommation.
 *
 * C'est la seule décision du module qui oriente de l'argent, donc la seule dont les trois issues
 * doivent être figées noir sur blanc. Le pendant Doctrine est couvert par
 * `App\Tests\Stay\Integration\DoctrineOpenStayLookupTest` — la logique ici, la traduction là-bas.
 */
final class OpenStayResolverTest extends TestCase
{
    /** @param list<Stay> $ouverts */
    private function resolveur(array $ouverts): OpenStayResolver
    {
        return new OpenStayResolver(new class($ouverts) implements OpenStayLookup {
            /** @param list<Stay> $ouverts */
            public function __construct(private readonly array $ouverts)
            {
            }

            /** @return list<Stay> */
            public function openStaysFor(Etablissement $establishment, Client $customer, \DateTimeImmutable $at): array
            {
                return $this->ouverts;
            }
        });
    }

    private function sejour(string $reference): Stay
    {
        return new Stay(
            $this->createStub(Etablissement::class),
            $this->createStub(Client::class),
            $reference,
            new \DateTimeImmutable('2026-08-24'),
            new \DateTimeImmutable('2026-08-24 15:00:00'),
        );
    }

    public function testUnClientDePassageNestPasUneAnomalie(): void
    {
        $resolution = $this->resolveur([])->resolve(
            $this->createStub(Etablissement::class),
            $this->createStub(Client::class),
            new \DateTimeImmutable('2026-08-25 19:00:00'),
        );

        self::assertSame(StayResolutionReason::NoOpenStay, $resolution->reason);
        self::assertNull($resolution->stay);
        // Le cas nominal du passant qui paie son entrée : il ne doit rien déclencher.
        self::assertFalse($resolution->needsAttention());
    }

    public function testUnSeulSejourOuvertEmporteLaConsommation(): void
    {
        $sejour = $this->sejour('SEJ-0001');

        $resolution = $this->resolveur([$sejour])->resolve(
            $this->createStub(Etablissement::class),
            $this->createStub(Client::class),
            new \DateTimeImmutable('2026-08-25 19:00:00'),
        );

        self::assertTrue($resolution->isMatched());
        self::assertSame($sejour, $resolution->stay);
    }

    public function testDeuxSejoursOuvertsNeSeDevinentPas(): void
    {
        $resolution = $this->resolveur([$this->sejour('SEJ-0001'), $this->sejour('SEJ-0002')])->resolve(
            $this->createStub(Etablissement::class),
            $this->createStub(Client::class),
            new \DateTimeImmutable('2026-08-25 19:00:00'),
        );

        // Debiter le mauvais compte se decouvre devant le client et se repare par un geste
        // commercial ; ne pas debiter se repare par une ligne ajoutee a la main.
        self::assertSame(StayResolutionReason::Ambiguous, $resolution->reason);
        self::assertNull($resolution->stay);
        self::assertTrue($resolution->needsAttention(), 'Une ambiguite doit etre portee a la connaissance de lexploitant.');
    }
}
