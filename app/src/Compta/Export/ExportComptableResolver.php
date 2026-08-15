<?php

declare(strict_types=1);

namespace App\Compta\Export;

use App\Compta\Enum\FormatExport;
use App\Compta\Port\ExportComptableInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Résout l'adaptateur d'export par format (itérateur taggé `compta.export_adapter`, aucun `switch`,
 * même pattern que `RegimeComptableResolver`).
 */
final class ExportComptableResolver
{
    /** @var array<string, ExportComptableInterface>|null */
    private ?array $parFormat = null;

    /**
     * @param iterable<ExportComptableInterface> $adaptateurs
     */
    public function __construct(
        #[AutowireIterator('compta.export_adapter')]
        private readonly iterable $adaptateurs,
    ) {
    }

    public function pour(FormatExport $format): ExportComptableInterface
    {
        $map = $this->map();

        return $map[$format->value] ?? throw new UnprocessableEntityHttpException(sprintf('Aucun adaptateur d\'export pour le format « %s ».', $format->value));
    }

    /** @return array<string, ExportComptableInterface> */
    private function map(): array
    {
        if ($this->parFormat !== null) {
            return $this->parFormat;
        }

        $map = [];
        foreach ($this->adaptateurs as $adaptateur) {
            $map[$adaptateur->format()->value] = $adaptateur;
        }

        return $this->parFormat = $map;
    }
}
