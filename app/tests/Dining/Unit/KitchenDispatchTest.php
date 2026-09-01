<?php

declare(strict_types=1);

namespace App\Tests\Dining\Unit;

use App\Dining\Domain\CourseRef;
use App\Dining\Domain\KitchenDispatch;
use App\Dining\Entity\DiningOrder;
use App\Dining\Entity\DiningOrderLine;
use App\Dining\Enum\LineStatus;
use App\Organisation\Entity\Etablissement;
use PHPUnit\Framework\TestCase;

/**
 * L'envoi en cuisine (ACT-4) : un service part d'un bloc, et jamais avant celui qui le precede.
 */
final class KitchenDispatchTest extends TestCase
{
    private const ENTREE = ['entree', 1];
    private const PLAT = ['plat', 2];
    private const DESSERT = ['dessert', 3];

    private function addition(): DiningOrder
    {
        return new DiningOrder(
            $this->createStub(Etablissement::class),
            'ADD-0001',
            '12',
            4,
            new \DateTimeImmutable('2026-09-01 12:00:00'),
        );
    }

    /** @param array{0: string, 1: int} $service */
    private function ligne(array $service, string $libelle): DiningOrderLine
    {
        return new DiningOrderLine($this->addition(), CourseRef::of($service[0], $service[1]), $libelle, 1, '12.00');
    }

    private function service(array $s): CourseRef
    {
        return CourseRef::of($s[0], $s[1]);
    }

    private function midi(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-01 12:30:00');
    }

    public function testUnServicePartDunBloc(): void
    {
        $entree1 = $this->ligne(self::ENTREE, 'Terrine');
        $entree2 = $this->ligne(self::ENTREE, 'Salade');
        $plat = $this->ligne(self::PLAT, 'Entrecote');

        $partis = (new KitchenDispatch())->fire($this->service(self::ENTREE), [$entree1, $entree2, $plat], $this->midi());

        self::assertCount(2, $partis, 'Les deux entrees partent ensemble.');
        self::assertSame(LineStatus::Fired, $entree1->getStatus());
        self::assertSame(LineStatus::Fired, $entree2->getStatus());
        self::assertSame(LineStatus::Draft, $plat->getStatus(), 'Le plat attend son tour.');
    }

    public function testOnNEnvoiePasLesPlatsQuandLesEntreesAttendentEncore(): void
    {
        // **La faute reelle du coup de feu** : on saisit tout, on envoie les plats, et les entrees
        // restent a l'ecran. La table recoit son plat principal en premier.
        $entree = $this->ligne(self::ENTREE, 'Terrine');
        $plat = $this->ligne(self::PLAT, 'Entrecote');

        $this->expectException(\LogicException::class);
        (new KitchenDispatch())->fire($this->service(self::PLAT), [$entree, $plat], $this->midi());
    }

    public function testUnServiceDejaEnvoyeNeBloqueRien(): void
    {
        // C'est le deroulement normal du repas : entrees parties, on envoie les plats.
        $entree = $this->ligne(self::ENTREE, 'Terrine')->fire($this->midi());
        $plat = $this->ligne(self::PLAT, 'Entrecote');

        $partis = (new KitchenDispatch())->fire($this->service(self::PLAT), [$entree, $plat], $this->midi());

        self::assertCount(1, $partis);
        self::assertSame(LineStatus::Fired, $plat->getStatus());
    }

    public function testUnEnvoiVideLeDitPlutotQueDeNeRienFaire(): void
    {
        // Un serveur qui appuie sur « envoyer » sans rien voir se passer appuiera une seconde fois,
        // puis ira voir en cuisine.
        $dessert = $this->ligne(self::DESSERT, 'Tarte')->fire($this->midi());

        $this->expectException(\LogicException::class);
        (new KitchenDispatch())->fire($this->service(self::DESSERT), [$dessert], $this->midi());
    }

    public function testUnRangEgalNestPasUnePrecedence(): void
    {
        // Fromage et dessert servis ensemble : meme rang, aucun ne bloque l'autre.
        $fromage = $this->ligne(['fromage', 3], 'Comte');
        $dessert = $this->ligne(self::DESSERT, 'Tarte');

        $partis = (new KitchenDispatch())->fire($this->service(self::DESSERT), [$fromage, $dessert], $this->midi());

        self::assertCount(1, $partis);
        self::assertSame(LineStatus::Draft, $fromage->getStatus());
    }
}
