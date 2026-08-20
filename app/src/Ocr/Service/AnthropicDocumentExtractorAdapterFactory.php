<?php

declare(strict_types=1);

namespace App\Ocr\Service;

use App\Ocr\Adapter\AnthropicDocumentExtractorAdapter;
use App\Ocr\Adapter\ManualExtractorAdapter;
use App\Ocr\DocumentExtractor;
use App\Ocr\Entity\OcrProviderConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Construit un `AnthropicDocumentExtractorAdapter` à la volée pour un établissement donné, avec sa
 * clé API déchiffrée (plan-ocr.md §0.3) — une instance par tenant, jamais un service unique partagé
 * (contrairement à `ManualExtractorAdapter`, statique et taggé `ocr.extractor`).
 *
 * Dégradation propre (invariant noyau commun #5) : aucune clé configurée ou déchiffrement impossible
 * → replie sur `ManualExtractorAdapter` plutôt que de construire un adaptateur inopérant.
 */
final class AnthropicDocumentExtractorAdapterFactory
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ChiffreurApiKeyOcr $chiffreur,
    ) {
    }

    public function pour(OcrProviderConfig $config): DocumentExtractor
    {
        $chiffre = $config->getApiKeyEncrypted();
        if ($chiffre === null || trim($chiffre) === '') {
            return new ManualExtractorAdapter();
        }

        try {
            $clair = $this->chiffreur->dechiffrer($chiffre);
        } catch (\Throwable) {
            return new ManualExtractorAdapter();
        }

        return new AnthropicDocumentExtractorAdapter($this->httpClient, $clair);
    }
}
