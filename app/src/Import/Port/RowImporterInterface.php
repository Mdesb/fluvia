<?php

declare(strict_types=1);

namespace App\Import\Port;

use App\Import\Dto\ParsedImportRow;
use App\Import\Entity\ImportBatch;
use App\Import\Enum\ImportType;
use App\Organisation\Entity\Etablissement;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/**
 * Point d'extension par type d'import (plan-import-i1.md §0.3) — un seul type implémenté par ce lot
 * (`customers`, `App\Import\Service\CustomerRowImporter`). Chaque type futur (I2+ : `products`,
 * `tariffs`, `subscribers`, `card_credits`, `staff`) ajoute une implémentation taguée, sans modifier ce
 * contrat ni `App\Import\Service\RowImporterRegistry`.
 *
 * Le tag est porté par l'interface (même patron que `App\Platform\Module\ModuleManifest`) : toute
 * implémentation est enregistrée au registre sans une ligne de configuration.
 *
 * `validate()` est **pure** : aucune écriture, une lecture au plus (recherche d'un `externalRef` déjà
 * connu, informatif seulement).
 */
#[AutoconfigureTag('app.import.row_importer')]
interface RowImporterInterface
{
    public function type(): ImportType;

    /**
     * @param list<ParsedImportRow> $rows
     *
     * @return array<int,string> ligne => message
     */
    public function validate(array $rows, Etablissement $establishment): array;

    /**
     * @param list<ParsedImportRow> $rows
     *
     * @return int nombre de créations (hors mises à jour, §0.5 du plan)
     */
    public function apply(array $rows, ImportBatch $batch): int;

    public function countCreated(Uuid $batchId): int;

    public function isReferenced(Uuid $batchId): bool;

    public function revert(Uuid $batchId): void;
}
