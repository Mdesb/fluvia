<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit;

use App\Import\Entity\ImportBatch;
use App\Import\Enum\ImportType;
use App\Import\Port\RowImporterInterface;
use App\Import\Service\RowImporterRegistry;
use App\Organisation\Entity\Etablissement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `RowImporterRegistry` (plan-import-i1.md §0.3) : dispatch générique par `type()`, absence
 * d'implémentation -> 422 explicite, jamais 500.
 */
final class RowImporterRegistryTest extends TestCase
{
    public function testTypeSansImporterEnregistreRefuse422Explicite(): void
    {
        $registry = new RowImporterRegistry([]);

        $this->expectException(UnprocessableEntityHttpException::class);

        $registry->forType(ImportType::Customers);
    }

    public function testResoutLimporterEnregistrePourSonType(): void
    {
        $importer = new class implements RowImporterInterface {
            public function type(): ImportType
            {
                return ImportType::Customers;
            }

            public function validate(array $rows, Etablissement $establishment): array
            {
                return [];
            }

            public function apply(array $rows, ImportBatch $batch): int
            {
                return 0;
            }

            public function countCreated(Uuid $batchId): int
            {
                return 0;
            }

            public function isReferenced(Uuid $batchId): bool
            {
                return false;
            }

            public function revert(Uuid $batchId): void
            {
            }
        };

        $registry = new RowImporterRegistry([$importer]);

        self::assertSame($importer, $registry->forType(ImportType::Customers));
    }
}
