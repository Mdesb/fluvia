<?php

declare(strict_types=1);

namespace App\Reporting\Service;

use App\Reporting\Enum\FormatExport;

/**
 * Agrège les `GenerateurExportInterface` par format (tag `reporting.generateur_export`, même
 * patron que `App\Reservation\Facturation\ResolveurStrategieFacturation`).
 */
final class ResolveurGenerateurExport
{
    /** @var array<string, GenerateurExportInterface> */
    private array $generateurs = [];

    /** @param iterable<GenerateurExportInterface> $generateurs */
    public function __construct(iterable $generateurs)
    {
        foreach ($generateurs as $generateur) {
            $this->generateurs[$generateur->format()->value] = $generateur;
        }
    }

    public function pour(FormatExport $format): GenerateurExportInterface
    {
        return $this->generateurs[$format->value] ?? throw new \LogicException(sprintf('Aucun générateur enregistré pour le format « %s ».', $format->value));
    }
}
