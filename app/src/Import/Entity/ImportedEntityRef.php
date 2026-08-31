<?php

declare(strict_types=1);

namespace App\Import\Entity;

use App\Import\Enum\ImportType;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Table de correspondance `externalRef` ↔ entité cible (`App\Import`, plan-import-i1.md §0.5/§1,
 * SPEC-REPRISE-INITIALE.md §3, D100) — **interne**, volontairement pas une `#[ApiResource]` : jamais
 * consultée directement par un client API, l'auditabilité passe par `ImportBatch` +
 * `Client.importBatchRef` (§2 point 4 du plan).
 *
 * `targetId` est **polymorphe par construction** (id de `Client` pour I1, d'autres entités cibles en
 * I2+) : volontairement **pas** de FK Doctrine (D2) — une seule table sert N types de cibles dans N
 * modules, une FK figerait la cible à un seul type.
 *
 * `UNIQUE(establishment, type, external_ref)` est la règle D100 elle-même : le rapprochement est exact
 * par construction, jamais une devinette.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_entity_ref')]
#[ORM\UniqueConstraint(name: 'uniq_import_entity_ref', columns: ['establishment_id', 'type', 'external_ref'])]
#[ORM\Index(columns: ['target_id'], name: 'idx_import_entity_ref_target')]
class ImportedEntityRef
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    private ?Etablissement $establishment = null;

    #[ORM\Column(length: 20, enumType: ImportType::class)]
    private ?ImportType $type = null;

    #[ORM\Column(name: 'external_ref', length: 190)]
    private string $externalRef = '';

    /** Id de l'entité cible (`Client.id` pour I1) — pas de FK (D2, polymorphe, cf. docblock). */
    #[ORM\Column(name: 'target_id', type: UuidType::NAME)]
    private ?Uuid $targetId = null;

    /**
     * Dernier lot qui a créé **ou** mis à jour cette ligne (informatif) — distinct de
     * `Client.importBatchRef`, posé une seule fois à la création et jamais réécrit (§0.6 du plan).
     */
    #[ORM\Column(name: 'import_batch_ref', type: UuidType::NAME)]
    private ?Uuid $importBatchRef = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getType(): ?ImportType
    {
        return $this->type;
    }

    public function setType(?ImportType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getExternalRef(): string
    {
        return $this->externalRef;
    }

    public function setExternalRef(string $externalRef): self
    {
        $this->externalRef = $externalRef;

        return $this;
    }

    public function getTargetId(): ?Uuid
    {
        return $this->targetId;
    }

    public function setTargetId(?Uuid $targetId): self
    {
        $this->targetId = $targetId;

        return $this;
    }

    public function getImportBatchRef(): ?Uuid
    {
        return $this->importBatchRef;
    }

    public function setImportBatchRef(?Uuid $importBatchRef): self
    {
        $this->importBatchRef = $importBatchRef;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
