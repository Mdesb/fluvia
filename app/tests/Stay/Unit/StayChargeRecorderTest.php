<?php

declare(strict_types=1);

namespace App\Tests\Stay\Unit;

use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Stay\Entity\Stay;
use App\Stay\Entity\StayCharge;
use App\Stay\Service\StayChargeLookup;
use App\Stay\Service\StayChargeRecorder;
use App\Stay\Service\StayFolio;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * La règle la plus coûteuse à se tromper du module : **ne jamais porter deux fois le même fait au
 * compte d'un client**. Couverte ici en unitaire, sans base, pour qu'elle reste vérifiée même les
 * jours où l'on renonce à monter une pile Docker — et le 24/08 il y avait 28 réseaux occupés.
 */
final class StayChargeRecorderTest extends TestCase
{
    private function sejour(): Stay
    {
        return new Stay(
            $this->createStub(Etablissement::class),
            $this->createStub(Client::class),
            'SEJ-0002',
            new \DateTimeImmutable('2026-08-24'),
            new \DateTimeImmutable('2026-08-24 15:00:00'),
        );
    }

    /** Un `StayChargeLookup` de test : ce qu'il connaît est passé au constructeur. */
    private function lookup(?StayCharge $dejaVue, array $montants = []): StayChargeLookup
    {
        return new class($dejaVue, $montants) implements StayChargeLookup {
            /** @param list<string> $montants */
            public function __construct(
                private readonly ?StayCharge $dejaVue,
                private readonly array $montants,
            ) {
            }

            public function findBySource(Stay $stay, string $sourceEvent, string $sourceSubjectId): ?StayCharge
            {
                return $this->dejaVue;
            }

            /** @return list<string> */
            public function amountsOf(Stay $stay): array
            {
                return $this->montants;
            }
        };
    }

    public function testUnFaitInconnuEstPorteAuCompte(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $ligne = (new StayChargeRecorder($em, $this->lookup(null)))->record(
            $this->sejour(), 'Bar — 2 demis', '9.00',
            new \DateTimeImmutable('2026-08-25 19:00:00'),
            'vente', 'sale.completed', 'sale-123',
        );

        self::assertInstanceOf(StayCharge::class, $ligne);
        self::assertSame('9.00', $ligne->getAmount());
    }

    public function testUnFaitDejaPorteNestPasPorteDeuxFois(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $sejour = $this->sejour();
        $existante = new StayCharge(
            $sejour, 'Bar — 2 demis', '9.00',
            new \DateTimeImmutable('2026-08-25 19:00:00'),
            'vente', 'sale.completed', 'sale-123',
        );

        // Le cas nominal du rejeu : le transport asynchrone relivre un message déjà traité (D7-bis).
        $ligne = (new StayChargeRecorder($em, $this->lookup($existante)))->record(
            $sejour, 'Bar — 2 demis', '9.00',
            new \DateTimeImmutable('2026-08-25 19:00:00'),
            'vente', 'sale.completed', 'sale-123',
        );

        self::assertNull($ligne);
    }

    public function testLaCoursePerdueSurLaContrainteDuniciteNestPasUneErreur(): void
    {
        // `createStub` et non `createMock` : aucune attente n'est posée ici, seul le comportement de
        // `flush()` compte (même famille que C23, qui a supprimé cinq notices de ce type).
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException($this->createStub(UniqueConstraintViolationException::class));

        // Deux abonnés concurrents sur le même fait : celui qui perd doit repartir en silence, pas
        // remonter une exception qui interromprait la vente (RG-PLAT-05, le bus est synchrone).
        $ligne = (new StayChargeRecorder($em, $this->lookup(null)))->record(
            $this->sejour(), 'Piscine — 1 entrée', '4.50',
            new \DateTimeImmutable('2026-08-25 14:30:00'),
            'acces', 'access.recorded', 'passage-77',
        );

        self::assertNull($ligne);
    }

    public function testLaNoteSeDeriveDesLignes(): void
    {
        $folio = new StayFolio($this->lookup(null, ['120.00', '9.00', '4.50']));

        self::assertSame('133.50', $folio->balanceOf($this->sejour())->total());
    }
}
