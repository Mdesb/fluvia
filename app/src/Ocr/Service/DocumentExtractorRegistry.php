<?php

declare(strict_types=1);

namespace App\Ocr\Service;

use App\Ocr\DocumentExtractor;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Résout un adaptateur `DocumentExtractor` par `provider()` (itérateur taggé `ocr.extractor`, aucun
 * `switch`, même patron que `App\Compta\Export\ExportComptableResolver`). Ne contient que les
 * adaptateurs **statiques** (`ManualExtractorAdapter`) — `AnthropicDocumentExtractorAdapter` n'y
 * figure pas (une instance par tenant, cf. `AnthropicDocumentExtractorAdapterFactory`, plan-ocr.md §0.3).
 */
final class DocumentExtractorRegistry
{
    /** @var array<string, DocumentExtractor>|null */
    private ?array $parProvider = null;

    /**
     * @param iterable<DocumentExtractor> $extracteurs
     */
    public function __construct(
        #[AutowireIterator('ocr.extractor')]
        private readonly iterable $extracteurs,
    ) {
    }

    public function pour(string $provider): DocumentExtractor
    {
        $map = $this->map();

        return $map[$provider] ?? throw new \InvalidArgumentException(sprintf('Aucun extracteur OCR enregistré pour le fournisseur « %s ».', $provider));
    }

    /** @return array<string, DocumentExtractor> */
    private function map(): array
    {
        if ($this->parProvider !== null) {
            return $this->parProvider;
        }

        $map = [];
        foreach ($this->extracteurs as $extracteur) {
            $map[$extracteur->provider()] = $extracteur;
        }

        return $this->parProvider = $map;
    }
}
