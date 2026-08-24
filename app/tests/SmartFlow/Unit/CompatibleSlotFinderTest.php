<?php

declare(strict_types=1);

namespace App\Tests\SmartFlow\Unit;

use App\SmartFlow\Dto\SlotSnapshot;
use App\SmartFlow\Service\CompatibleSlotFinder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** RG-SF-09 (§0.6 du plan) : même ressource OU même codeType, capacité résiduelle > 0. Pure, sans base de données. */
final class CompatibleSlotFinderTest extends TestCase
{
    public function testMemeRessourceOuMemeCodeTypeDansLaFenetre(): void
    {
        $establishment = Uuid::v4();
        $resourceA = Uuid::v4();
        $resourceB = Uuid::v4();

        $origin = $this->slot($establishment, Uuid::v4(), $resourceA, 'padel', 5);

        // Même ressource, codeType différent -> compatible (union, pas intersection).
        $sameResource = $this->slot($establishment, Uuid::v4(), $resourceA, 'autre_type', 3);
        // Ressource différente, même codeType -> compatible.
        $sameType = $this->slot($establishment, Uuid::v4(), $resourceB, 'padel', 2);
        // Ni l'un ni l'autre -> non compatible.
        $incompatible = $this->slot($establishment, Uuid::v4(), $resourceB, 'squash', 4);

        $finder = new CompatibleSlotFinder();

        self::assertSame($sameResource, $finder->selectCompatible($origin, [$incompatible, $sameResource, $sameType]));
        self::assertSame($sameType, $finder->selectCompatible($origin, [$incompatible, $sameType]));
        self::assertNull($finder->selectCompatible($origin, [$incompatible]));
    }

    public function testCapaciteResiduelleNulleExclue(): void
    {
        $establishment = Uuid::v4();
        $resource = Uuid::v4();
        $origin = $this->slot($establishment, Uuid::v4(), $resource, 'padel', 5);

        $complet = $this->slot($establishment, Uuid::v4(), $resource, 'padel', 0);
        $disponible = $this->slot($establishment, Uuid::v4(), $resource, 'padel', 1);

        $finder = new CompatibleSlotFinder();

        self::assertSame($disponible, $finder->selectCompatible($origin, [$complet, $disponible]));
        self::assertNull($finder->selectCompatible($origin, [$complet]), 'Capacité résiduelle nulle -> exclu (RG-SF-09).');
    }

    public function testLeCreneauDoriginenEstJamaisReproposeALuiMeme(): void
    {
        $establishment = Uuid::v4();
        $resource = Uuid::v4();
        $originId = Uuid::v4();
        $origin = $this->slot($establishment, $originId, $resource, 'padel', 5);

        // Même id que l'origine, techniquement "compatible" et disponible -> doit être ignoré.
        $doublon = $this->slot($establishment, $originId, $resource, 'padel', 5);

        $finder = new CompatibleSlotFinder();

        self::assertNull($finder->selectCompatible($origin, [$doublon]));
    }

    private function slot(Uuid $establishment, Uuid $id, ?Uuid $resourceId, ?string $codeType, int $residual): SlotSnapshot
    {
        return new SlotSnapshot(
            id: $id,
            establishmentId: $establishment,
            resourceId: $resourceId,
            codeType: $codeType,
            start: new \DateTimeImmutable('+1 day'),
            end: new \DateTimeImmutable('+1 day +1 hour'),
            residualCapacity: $residual,
        );
    }
}
