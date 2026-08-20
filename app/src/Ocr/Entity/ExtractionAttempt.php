<?php

declare(strict_types=1);

namespace App\Ocr\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Ocr\Enum\DocumentKind;
use App\Ocr\Enum\ExtractionStatus;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Traçabilité append-only de chaque tentative d'extraction (US-OCR-04, RG-OCR-04) — journalisée
 * systématiquement par `App\Ocr\Service\TenantAwareDocumentExtractor`, quel que soit le statut
 * (succès, faible confiance, échec), y compris en cas de bascule infrastructure. Ne porte **jamais**
 * le document source lui-même (`extractedFields` est un snapshot des champs structurés uniquement,
 * `rawText` tronqué — plan-ocr.md §7 point 6).
 *
 * Lecture seule côté API (aucun `Post`/`Patch`/`Delete` exposé) : permet au consommateur (FIN-2/FIN-3)
 * d'afficher « pré-rempli par IA, à vérifier » et au Comptable/Superviseur d'arbitrer un litige de
 * saisie (RG-OCR-05, `ocr.read_extraction`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ocr_extraction_attempt')]
#[ORM\Index(columns: ['establishment_id', 'status'], name: 'IDX_OCR_ATTEMPT_ETAB_STATUS')]
#[ApiResource(
    shortName: 'ExtractionAttempt',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'ocr.read_extraction')"),
        new Get(security: "is_granted('PERM', 'ocr.read_extraction')"),
    ],
    normalizationContext: ['groups' => ['extraction_attempt:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['documentKind' => 'exact', 'status' => 'exact', 'establishment' => 'exact'])]
class ExtractionAttempt
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['extraction_attempt:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    #[Groups(['extraction_attempt:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(name: 'document_kind', length: 20, enumType: DocumentKind::class)]
    #[Groups(['extraction_attempt:read'])]
    private DocumentKind $documentKind;

    #[ORM\Column(length: 30)]
    #[Groups(['extraction_attempt:read'])]
    private string $provider = '';

    #[ORM\Column(length: 20, enumType: ExtractionStatus::class)]
    #[Groups(['extraction_attempt:read'])]
    private ExtractionStatus $status;

    #[ORM\Column(name: 'confidence_score', type: 'float', nullable: true)]
    #[Groups(['extraction_attempt:read'])]
    private ?float $confidenceScore = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'extracted_fields', type: 'json', nullable: true)]
    #[Groups(['extraction_attempt:read'])]
    private ?array $extractedFields = null;

    #[ORM\Column(name: 'requested_at', type: 'datetime_immutable')]
    #[Groups(['extraction_attempt:read'])]
    private \DateTimeImmutable $requestedAt;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'requested_by_id', nullable: true)]
    #[Groups(['extraction_attempt:read'])]
    private ?Utilisateur $requestedBy = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEstablishment(): ?Etablissement
    {
        return $this->establishment;
    }

    public function setEstablishment(?Etablissement $establishment): self
    {
        $this->establishment = $establishment;

        return $this;
    }

    public function getDocumentKind(): DocumentKind
    {
        return $this->documentKind;
    }

    public function setDocumentKind(DocumentKind $documentKind): self
    {
        $this->documentKind = $documentKind;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getStatus(): ExtractionStatus
    {
        return $this->status;
    }

    public function setStatus(ExtractionStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getConfidenceScore(): ?float
    {
        return $this->confidenceScore;
    }

    public function setConfidenceScore(?float $confidenceScore): self
    {
        $this->confidenceScore = $confidenceScore;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getExtractedFields(): ?array
    {
        return $this->extractedFields;
    }

    /** @param array<string, mixed>|null $extractedFields */
    public function setExtractedFields(?array $extractedFields): self
    {
        $this->extractedFields = $extractedFields;

        return $this;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function setRequestedAt(\DateTimeImmutable $requestedAt): self
    {
        $this->requestedAt = $requestedAt;

        return $this;
    }

    public function getRequestedBy(): ?Utilisateur
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(?Utilisateur $requestedBy): self
    {
        $this->requestedBy = $requestedBy;

        return $this;
    }
}
