<?php

declare(strict_types=1);

namespace App\Ocr\Service;

use App\Ocr\Entity\OcrProviderConfig;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Résout la configuration OCR active d'un établissement (RG-OCR-06) — `null` si aucune configuration
 * explicite n'existe encore (défaut `manual`, cf. `TenantAwareDocumentExtractor`).
 */
final class OcrProviderConfigResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function pour(Etablissement $etablissement): ?OcrProviderConfig
    {
        return $this->em->getRepository(OcrProviderConfig::class)->findOneBy(['establishment' => $etablissement]);
    }
}
