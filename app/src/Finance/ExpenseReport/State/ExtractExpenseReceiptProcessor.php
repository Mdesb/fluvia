<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Ocr\DocumentExtractor;
use App\Ocr\Dto\DocumentToExtract;
use App\Ocr\Entity\ExtractionAttempt;
use App\Ocr\Enum\DocumentKind;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /finance/expense-reports/extract (§0.8 du plan) : corps `{ content: base64, mimeType }`,
 * `read:false`, `input:false`, `output:false`. Appelle `App\Ocr\DocumentExtractor` avec
 * `DocumentKind::ExpenseReceipt` (valeur déjà présente dans l'enum, aucune extension nécessaire) et
 * renvoie les champs extraits + l'IRI de l'`ExtractionAttempt` créé.
 *
 * **Aucune création automatique de ligne** (RG-EXP-03) : ce endpoint assiste seulement le
 * pré-remplissage du formulaire client, qui enregistre ensuite une `ExpenseLine` normalement. Mode
 * dégradé toujours disponible (OCR en échec/non configuré -> `status: failed`, saisie manuelle
 * intégralement fonctionnelle, même patron que FIN-2 §0.3).
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class ExtractExpenseReceiptProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly DocumentExtractor $extractor,
        private readonly ContexteEtablissement $contexte,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $corps = $this->lecteur->corps();
        $contenuBase64 = $corps['content'] ?? null;
        $mimeType = $corps['mimeType'] ?? null;

        if (!\is_string($contenuBase64) || $contenuBase64 === '' || !\is_string($mimeType) || $mimeType === '') {
            throw new UnprocessableEntityHttpException('« content » (base64) et « mimeType » sont requis.');
        }

        $binaire = base64_decode($contenuBase64, true);
        if ($binaire === false) {
            throw new UnprocessableEntityHttpException('« content » n\'est pas du base64 valide.');
        }

        $resultat = $this->extractor->extract(new DocumentToExtract($binaire, $mimeType), DocumentKind::ExpenseReceipt);

        $etablissement = $this->contexte->etablissementActif();
        $iriExtraction = null;
        if ($etablissement !== null) {
            $derniere = $this->em->getRepository(ExtractionAttempt::class)->findOneBy(
                ['establishment' => $etablissement->getId(), 'documentKind' => DocumentKind::ExpenseReceipt],
                ['requestedAt' => 'DESC'],
            );
            if ($derniere instanceof ExtractionAttempt) {
                $iriExtraction = '/api/extraction_attempts/' . $derniere->getId();
            }
        }

        return new JsonResponse([
            'status' => $resultat->status->value,
            'provider' => $resultat->provider,
            'supplierName' => $resultat->supplierName,
            'documentDate' => $resultat->documentDate?->format('Y-m-d'),
            'amountExclTax' => $resultat->amountExclTax,
            'amountInclTax' => $resultat->amountInclTax,
            'vatAmount' => $resultat->vatAmount,
            'vatRate' => $resultat->vatRate,
            'confidenceScore' => $resultat->confidenceScore,
            'ocrExtraction' => $iriExtraction,
        ]);
    }
}
