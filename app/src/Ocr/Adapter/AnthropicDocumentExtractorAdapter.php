<?php

declare(strict_types=1);

namespace App\Ocr\Adapter;

use App\Ocr\DocumentExtractor;
use App\Ocr\Dto\DocumentToExtract;
use App\Ocr\Dto\ExtractedDocument;
use App\Ocr\Enum\DocumentKind;
use App\Ocr\Enum\ExtractionStatus;
use App\Ocr\Enum\OcrProvider;
use App\Ocr\Exception\OcrProviderUnavailableException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Implémentation réelle branchable sur l'API Anthropic (vision multimodale, US-OCR-03, RG-OCR-03).
 * ⚠ HYPOTHÈSE (plan-ocr.md §7 point 1, non tranchée avec le propriétaire produit — impact coût direct) :
 * modèle exact, prompt, format de sortie strict non fixés par la spec — comportement observable
 * uniquement garanti ici : JSON strict → `success` + champs ; JSON non conforme → `low_confidence`
 * (**jamais** `failed`, le document a bien été soumis) ; échec de transport → `OcrProviderUnavailableException`.
 *
 * Non taggé `ocr.extractor` (une instance par tenant, clé API décryptée à la volée) : produit
 * exclusivement par `App\Ocr\Service\AnthropicDocumentExtractorAdapterFactory`. `$apiKey` porte un
 * défaut `''` uniquement pour rester compilable si jamais auto-enregistré comme service générique
 * (jamais utilisé ainsi en pratique — instancié à la main par la factory) ; une clé vide dégrade
 * immédiatement en `failed` sans appel réseau (dégradation propre, invariant noyau commun #5).
 */
final class AnthropicDocumentExtractorAdapter implements DocumentExtractor
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const DEFAULT_MODEL = 'claude-3-5-sonnet-20241022';
    private const MAX_TOKENS = 1024;
    /** Taille maximale conservée pour `rawText` (plan-ocr.md §7 point 6, valeur de convenance). */
    private const RAW_TEXT_MAX_LENGTH = 5000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey = '',
        private readonly string $model = self::DEFAULT_MODEL,
    ) {
    }

    public function provider(): string
    {
        return OcrProvider::Anthropic->value;
    }

    public function extract(DocumentToExtract $document, DocumentKind $kind): ExtractedDocument
    {
        if (trim($this->apiKey) === '') {
            // Aucune clé exploitable : dégradation propre, aucun appel réseau (invariant noyau commun #5).
            return new ExtractedDocument(status: ExtractionStatus::Failed, provider: $this->provider());
        }

        try {
            $reponse = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                    'content-type' => 'application/json',
                ],
                'json' => $this->construirePayload($document, $kind),
            ]);
            $corps = $reponse->toArray();
        } catch (HttpExceptionInterface $e) {
            // Seul cas d'exception légitime (RG-OCR-01 docblock) : timeout, DNS, 4xx/5xx du fournisseur.
            throw new OcrProviderUnavailableException('Fournisseur Anthropic injoignable.', previous: $e);
        }

        return $this->analyserReponse($corps);
    }

    /** @return array<string, mixed> */
    private function construirePayload(DocumentToExtract $document, DocumentKind $kind): array
    {
        $typeSource = str_starts_with($document->mimeType, 'application/pdf') ? 'document' : 'image';

        return [
            'model' => $this->model,
            'max_tokens' => self::MAX_TOKENS,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => $typeSource,
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $document->mimeType,
                                'data' => base64_encode($document->content),
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $this->construirePrompt($kind),
                        ],
                    ],
                ],
            ],
        ];
    }

    private function construirePrompt(DocumentKind $kind): string
    {
        return sprintf(
            'You are extracting structured data from a %s. Respond with a single strict JSON object only '
            . '(no markdown, no prose) with exactly these keys: supplierName, documentNumber, documentDate '
            . '(ISO 8601 date, e.g. 2026-01-31), amountExclTax, amountInclTax, vatAmount, vatRate (as decimal '
            . 'strings, e.g. "123.45"), confidenceScore (number between 0 and 1), rawText (short excerpt). '
            . 'Use null for any field you cannot read confidently.',
            $kind === DocumentKind::SupplierInvoice ? 'supplier invoice' : 'expense receipt',
        );
    }

    /** @param array<string, mixed> $corps */
    private function analyserReponse(array $corps): ExtractedDocument
    {
        $texte = $this->extraireTexte($corps);
        $donnees = $texte !== null ? json_decode($texte, true) : null;

        if (!\is_array($donnees)) {
            // Réponse non conforme au schéma attendu (RG-OCR-03) : le document a bien été traité, la
            // confiance est simplement dégradée — jamais `failed` dans ce cas précis.
            return new ExtractedDocument(
                status: ExtractionStatus::LowConfidence,
                provider: $this->provider(),
                rawText: $texte !== null ? $this->tronquer($texte) : null,
            );
        }

        return new ExtractedDocument(
            status: ExtractionStatus::Success,
            provider: $this->provider(),
            supplierName: $this->chaineOuNull($donnees['supplierName'] ?? null),
            documentNumber: $this->chaineOuNull($donnees['documentNumber'] ?? null),
            documentDate: $this->dateOuNull($donnees['documentDate'] ?? null),
            amountExclTax: $this->chaineOuNull($donnees['amountExclTax'] ?? null),
            amountInclTax: $this->chaineOuNull($donnees['amountInclTax'] ?? null),
            vatAmount: $this->chaineOuNull($donnees['vatAmount'] ?? null),
            vatRate: $this->chaineOuNull($donnees['vatRate'] ?? null),
            confidenceScore: \is_numeric($donnees['confidenceScore'] ?? null) ? (float) $donnees['confidenceScore'] : null,
            rawText: isset($donnees['rawText']) && \is_string($donnees['rawText']) ? $this->tronquer($donnees['rawText']) : null,
        );
    }

    /** @param array<string, mixed> $corps */
    private function extraireTexte(array $corps): ?string
    {
        $blocs = $corps['content'] ?? null;
        if (!\is_array($blocs)) {
            return null;
        }

        foreach ($blocs as $bloc) {
            if (\is_array($bloc) && ($bloc['type'] ?? null) === 'text' && \is_string($bloc['text'] ?? null)) {
                return $bloc['text'];
            }
        }

        return null;
    }

    private function chaineOuNull(mixed $valeur): ?string
    {
        return \is_string($valeur) && trim($valeur) !== '' ? $valeur : null;
    }

    private function dateOuNull(mixed $valeur): ?\DateTimeImmutable
    {
        if (!\is_string($valeur) || trim($valeur) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Exception) {
            return null;
        }
    }

    private function tronquer(string $texte): string
    {
        return mb_substr($texte, 0, self::RAW_TEXT_MAX_LENGTH);
    }
}
