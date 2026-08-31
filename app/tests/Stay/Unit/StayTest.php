<?php

declare(strict_types=1);

namespace App\Tests\Stay\Unit;

use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Stay\Entity\Stay;
use App\Stay\Entity\StayCharge;
use App\Stay\Enum\StayStatus;
use PHPUnit\Framework\TestCase;

/**
 * Invariants du séjour (ACT-3, D16). Ces tests portent sur le **modèle seul** : pas de base, pas de
 * conteneur — les règles vérifiées ici doivent tenir avant même qu'un listener existe, parce que ce
 * sont elles qui empêcheront un listener bogué de facturer deux fois ou de charger un compte clos.
 *
 * `createStub` plutôt que `createMock` : aucune attente n'est posée sur l'établissement ni sur le
 * client, seulement leur identité de type (même famille que C23).
 */
final class StayTest extends TestCase
{
    private function sejourOuvert(): Stay
    {
        return new Stay(
            $this->createStub(Etablissement::class),
            $this->createStub(Client::class),
            'SEJ-0001',
            new \DateTimeImmutable('2026-08-24'),
            new \DateTimeImmutable('2026-08-24 15:00:00'),
        );
    }

    public function testUnSejourNaitOuvertEtAccepteDesLignes(): void
    {
        $sejour = $this->sejourOuvert();

        self::assertSame(StayStatus::Open, $sejour->getStatus());
        self::assertTrue($sejour->acceptsCharges());
        self::assertNull($sejour->getClosedAt());
        self::assertNull($sejour->getSettledAt());
    }

    public function testLaDateDeDepartResteFacultative(): void
    {
        // Un camping accepte des séjours sans date de fin ; forcer une date inventée obligerait à la
        // corriger tous les matins.
        self::assertNull($this->sejourOuvert()->getExpectedDepartureDate());
    }

    public function testClotureIdempotenteEtDatee(): void
    {
        $sejour = $this->sejourOuvert();
        $premierDepart = new \DateTimeImmutable('2026-08-28 10:00:00');

        $sejour->close($premierDepart);
        $sejour->close(new \DateTimeImmutable('2026-08-28 10:05:00'));

        // Le comptoir clôture parfois deux fois : la seconde ne doit ni lever ni réécrire l'heure.
        self::assertSame(StayStatus::Closed, $sejour->getStatus());
        self::assertEquals($premierDepart, $sejour->getClosedAt());
    }

    public function testUnSejourClosNaccepteplusDeLigne(): void
    {
        $sejour = $this->sejourOuvert();
        $sejour->close(new \DateTimeImmutable('2026-08-28 10:00:00'));

        self::assertFalse($sejour->acceptsCharges());

        $this->expectException(\LogicException::class);
        new StayCharge(
            $sejour,
            'Bar — 2 demis',
            '9.00',
            new \DateTimeImmutable('2026-08-28 11:00:00'),
            'vente',
            'sale.completed',
            'sale-123',
        );
    }

    public function testUnSejourOuvertNePeutPasEtreSolde(): void
    {
        // Sinon il accepterait une ligne juste après, et le « réglé une fois » de D16 serait faux.
        $this->expectException(\LogicException::class);
        $this->sejourOuvert()->settle(new \DateTimeImmutable('2026-08-28 10:00:00'));
    }

    public function testReglementPostérieurAuDepart(): void
    {
        $sejour = $this->sejourOuvert();
        $sejour->close(new \DateTimeImmutable('2026-08-28 10:00:00'));
        $sejour->settle(new \DateTimeImmutable('2026-09-15 09:00:00'));

        // Partir et payer sont deux faits distincts : facturation différée à un comité d'entreprise,
        // litige sur une ligne. Les deux dates doivent pouvoir diverger.
        self::assertSame(StayStatus::Settled, $sejour->getStatus());
        self::assertNotEquals($sejour->getClosedAt(), $sejour->getSettledAt());
    }

    public function testUneLignePorteSonPropreEtablissement(): void
    {
        $sejour = $this->sejourOuvert();

        $ligne = new StayCharge(
            $sejour,
            'Piscine — 1 entrée',
            '4.50',
            new \DateTimeImmutable('2026-08-25 14:30:00'),
            'acces',
            'access.recorded',
            'passage-77',
        );

        // D8 : une ligne récupérée par son id, sans passer par son séjour, doit rester cloisonnable.
        self::assertSame($sejour->getEstablishment(), $ligne->getEstablishment());
        self::assertSame('access.recorded', $ligne->getSourceEvent());
        self::assertSame('passage-77', $ligne->getSourceSubjectId());
    }
}
