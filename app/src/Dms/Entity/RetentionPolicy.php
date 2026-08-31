<?php

declare(strict_types=1);

namespace App\Dms\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Dms\Enum\DocumentCategory;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Catalogue fixe v1 des politiques de rétention (RG-DMS-11, arbitrage D18 pt.7) — pas de configuration
 * par établissement, non cloisonné (`DmsScopeExtension` l'ignore volontairement).
 *
 * ⚠ Écart vs. la lecture littérale de spec-dms.md §5 (« PK = `code` ») : `id` UUID reste la PK pour
 * rester conforme à l'invariant constitution « id = UUID partout », `code` devient une colonne
 * **unique** — comportement observable identique, le `code` reste la clé métier visible (plan §14 pt.4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'dms_retention_policy')]
#[ORM\UniqueConstraint(name: 'uniq_dms_retention_policy_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_dms_retention_policy_default_category', columns: ['default_for_category'])]
#[ApiResource(
    shortName: 'RetentionPolicy',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'dms.read')"),
        new Get(security: "is_granted('PERM', 'dms.read')"),
        // Catalogue fixe v1 : aucun CRUD exposé.
    ],
    normalizationContext: ['groups' => ['retention_policy:read']],
)]
class RetentionPolicy
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['retention_policy:read'])]
    private Uuid $id;

    #[ORM\Column(length: 60)]
    #[Groups(['retention_policy:read'])]
    private string $code;

    #[ORM\Column(name: 'duration_months')]
    #[Groups(['retention_policy:read'])]
    private int $durationMonths;

    #[ORM\Column(name: 'legal_basis_key', length: 120)]
    #[Groups(['retention_policy:read'])]
    private string $legalBasisKey;

    #[ORM\Column(name: 'default_for_category', length: 30, enumType: DocumentCategory::class, nullable: true)]
    #[Groups(['retention_policy:read'])]
    private ?DocumentCategory $defaultForCategory = null;

    public function __construct(string $code, int $durationMonths, string $legalBasisKey, ?DocumentCategory $defaultForCategory = null)
    {
        $this->id = Uuid::v4();
        $this->code = $code;
        $this->durationMonths = $durationMonths;
        $this->legalBasisKey = $legalBasisKey;
        $this->defaultForCategory = $defaultForCategory;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getDurationMonths(): int
    {
        return $this->durationMonths;
    }

    public function getLegalBasisKey(): string
    {
        return $this->legalBasisKey;
    }

    public function getDefaultForCategory(): ?DocumentCategory
    {
        return $this->defaultForCategory;
    }
}
