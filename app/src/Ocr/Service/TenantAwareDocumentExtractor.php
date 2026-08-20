<?php

declare(strict_types=1);

namespace App\Ocr\Service;

use App\Ocr\Adapter\ManualExtractorAdapter;
use App\Ocr\DocumentExtractor;
use App\Ocr\Dto\DocumentToExtract;
use App\Ocr\Dto\ExtractedDocument;
use App\Ocr\Entity\ExtractionAttempt;
use App\Ocr\Entity\OcrProviderConfig;
use App\Ocr\Enum\DocumentKind;
use App\Ocr\Enum\ExtractionStatus;
use App\Ocr\Enum\OcrProvider;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Service composite qui implémente lui-même `DocumentExtractor` (plan-ocr.md §0.2 — décision
 * d'architecture au-delà du texte littéral de la spec, qui met le fallback côté « appelant » ; ne
 * contredit aucun CA, les renforce). Aliasé en DI vers `App\Ocr\DocumentExtractor`
 * (`#[AsAlias]` — équivalent, sans toucher `config/services.yaml` hors périmètre de ce lot, au
 * binding YAML `App\Ocr\DocumentExtractor: '@...TenantAwareDocumentExtractor'` du plan) : tout
 * consommateur qui type-hinte l'interface obtient ce comportement résilient par défaut.
 *
 * 1. résout l'établissement actif (`ContexteEtablissement`) et son `OcrProviderConfig` (défaut
 *    `manual` si absent, RG-OCR-06) ;
 * 2. délègue à l'adaptateur concret (`ManualExtractorAdapter` ou une instance
 *    `AnthropicDocumentExtractorAdapter` construite à la volée avec la clé déchiffrée du tenant) ;
 * 3. capture tout `\Throwable` de l'adaptateur délégué et retombe sur un résultat `failed` (CA-4) —
 *    dégradation automatique et centralisée, sans que le consommateur écrive le moindre `try/catch` ;
 * 4. réévalue `confidenceScore` contre `OcrProviderConfig::confidenceThreshold` (RG-OCR-05, défaut
 *    0.7) et rétrograde un `success` en `low_confidence` si le seuil n'est pas atteint (CA-5) ;
 * 5. journalise systématiquement un `ExtractionAttempt` (RG-OCR-04), y compris en cas de bascule
 *    infrastructure — sauf si aucun établissement actif n'est résolvable (FK `establishment` requise,
 *    échec fermé : le résultat dégradé est tout de même retourné, jamais d'exception non gérée).
 */
#[AsAlias(id: DocumentExtractor::class)]
final class TenantAwareDocumentExtractor implements DocumentExtractor
{
    private const DEFAULT_CONFIDENCE_THRESHOLD = 0.7;

    public function __construct(
        private readonly ContexteEtablissement $contexteEtablissement,
        private readonly OcrProviderConfigResolver $configResolver,
        private readonly ManualExtractorAdapter $manualExtractorAdapter,
        private readonly AnthropicDocumentExtractorAdapterFactory $anthropicFactory,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function provider(): string
    {
        return 'tenant_aware';
    }

    public function extract(DocumentToExtract $document, DocumentKind $kind): ExtractedDocument
    {
        $etablissement = $this->contexteEtablissement->etablissementActif();
        if ($etablissement === null) {
            // Échec fermé (plan-ocr.md §3) : aucun établissement actif résolvable côté serveur — jamais
            // un id porté par le DTO, qui reste générique sans notion de tenant.
            return new ExtractedDocument(status: ExtractionStatus::Failed, provider: OcrProvider::Manual->value);
        }

        $config = $this->configResolver->pour($etablissement);
        $seuil = $config !== null ? (float) $config->getConfidenceThreshold() : self::DEFAULT_CONFIDENCE_THRESHOLD;
        $delegue = $this->resoudreDelegue($config);

        try {
            $resultat = $delegue->extract($document, $kind);
        } catch (\Throwable $e) {
            $this->logger->warning('Extraction OCR en échec, bascule sur le mode dégradé.', [
                'provider' => $delegue->provider(),
                'exception' => $e->getMessage(),
            ]);
            $resultat = new ExtractedDocument(status: ExtractionStatus::Failed, provider: $delegue->provider());
        }

        if ($resultat->status === ExtractionStatus::Success && $resultat->confidenceScore !== null && $resultat->confidenceScore < $seuil) {
            $resultat = $resultat->withStatus(ExtractionStatus::LowConfidence);
        }

        $this->journaliser($etablissement, $kind, $resultat);

        return $resultat;
    }

    private function resoudreDelegue(?OcrProviderConfig $config): DocumentExtractor
    {
        if ($config === null || $config->getProvider() === OcrProvider::Manual) {
            return $this->manualExtractorAdapter;
        }

        return $this->anthropicFactory->pour($config);
    }

    private function journaliser(Etablissement $etablissement, DocumentKind $kind, ExtractedDocument $resultat): void
    {
        $tentative = new ExtractionAttempt();
        $tentative->setEstablishment($etablissement)
            ->setDocumentKind($kind)
            ->setProvider($resultat->provider)
            ->setStatus($resultat->status)
            ->setConfidenceScore($resultat->confidenceScore)
            ->setExtractedFields($this->snapshot($resultat))
            ->setRequestedAt(new \DateTimeImmutable())
            ->setRequestedBy($this->utilisateurCourant());

        $this->em->persist($tentative);
        $this->em->flush();
    }

    private function utilisateurCourant(): ?Utilisateur
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur : null;
    }

    /** @return array<string, mixed> */
    private function snapshot(ExtractedDocument $resultat): array
    {
        return [
            'supplierName' => $resultat->supplierName,
            'documentNumber' => $resultat->documentNumber,
            'documentDate' => $resultat->documentDate?->format('Y-m-d'),
            'amountExclTax' => $resultat->amountExclTax,
            'amountInclTax' => $resultat->amountInclTax,
            'vatAmount' => $resultat->vatAmount,
            'vatRate' => $resultat->vatRate,
            'confidenceScore' => $resultat->confidenceScore,
            // Tronqué (plan-ocr.md §7 point 6) : jamais le contenu binaire du document source, seul un
            // extrait audit optionnel du texte déjà tronqué par l'adaptateur.
            'rawText' => $resultat->rawText,
        ];
    }
}
