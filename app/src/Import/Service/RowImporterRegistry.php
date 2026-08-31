<?php

declare(strict_types=1);

namespace App\Import\Service;

use App\Import\Enum\ImportType;
use App\Import\Port\RowImporterInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Résout le `RowImporterInterface` d'un `ImportType` (plan-import-i1.md §0.3), même idiome que
 * `App\Finance\Treasury\Port\BankStatementParserInterface::supports()` — dispatch par `type()`, absence
 * d'implémentation -> 422 explicite (« type non encore supporté »), jamais 500. Point d'extension pour
 * I2+ : chaque nouveau `RowImporter` s'enregistre par le tag (porté par `RowImporterInterface`), zéro
 * modification de ce registre.
 */
final class RowImporterRegistry
{
    /** @var array<string, RowImporterInterface> */
    private array $importers = [];

    /**
     * @param iterable<RowImporterInterface> $importers
     */
    public function __construct(
        #[AutowireIterator('app.import.row_importer')]
        iterable $importers,
    ) {
        foreach ($importers as $importer) {
            $this->importers[$importer->type()->value] = $importer;
        }
    }

    public function forType(ImportType $type): RowImporterInterface
    {
        $importer = $this->importers[$type->value] ?? null;
        if ($importer === null) {
            throw new UnprocessableEntityHttpException(sprintf('Type d\'import « %s » non encore supporté.', $type->value));
        }

        return $importer;
    }
}
