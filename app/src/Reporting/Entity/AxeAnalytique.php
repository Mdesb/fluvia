<?php

declare(strict_types=1);

namespace App\Reporting\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Reporting\Enum\TypeAxeAnalytique;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Référentiel léger des axes analytiques de l'Explorateur (§1.2 plan-reporting.md, RG-M7-05).
 * Jamais supprimé (cas limite spec §7) : seule `Patch(actif=false)` désactive un axe référencé.
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_axe_analytique')]
#[ORM\UniqueConstraint(name: 'uniq_axe_code', columns: ['code'])]
#[ApiResource(
    shortName: 'AxeAnalytique',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reporting.lire')"),
        new Get(security: "is_granted('PERM', 'reporting.lire')"),
        new Post(security: "is_granted('PERM', 'reporting.configurer')"),
        new Patch(security: "is_granted('PERM', 'reporting.configurer')"),
    ],
    normalizationContext: ['groups' => ['axe:read']],
    denormalizationContext: ['groups' => ['axe:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['code' => 'exact', 'type' => 'exact', 'actif' => 'exact'])]
class AxeAnalytique
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['axe:read'])]
    private Uuid $id;

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank]
    #[Groups(['axe:read', 'axe:write'])]
    private string $code = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Groups(['axe:read', 'axe:write'])]
    private string $libelle = '';

    #[ORM\Column(length: 20, enumType: TypeAxeAnalytique::class)]
    #[Assert\NotNull]
    #[Groups(['axe:read', 'axe:write'])]
    private TypeAxeAnalytique $type = TypeAxeAnalytique::Site;

    /** @var list<string>|null Granularités disponibles, requis pour l'axe `periode` (jour/semaine/mois/année). */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['axe:read', 'axe:write'])]
    private ?array $granularites = null;

    /** Trace `categorie`/`canal` comme extensions non littéralement au cahier M7 (§4.3 spec). */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['axe:read', 'axe:write'])]
    private bool $estExtension = false;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['axe:read', 'axe:write'])]
    private bool $actif = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getType(): TypeAxeAnalytique
    {
        return $this->type;
    }

    public function setType(TypeAxeAnalytique $type): self
    {
        $this->type = $type;

        return $this;
    }

    /** @return list<string>|null */
    public function getGranularites(): ?array
    {
        return $this->granularites;
    }

    /** @param list<string>|null $granularites */
    public function setGranularites(?array $granularites): self
    {
        $this->granularites = $granularites;

        return $this;
    }

    public function isEstExtension(): bool
    {
        return $this->estExtension;
    }

    public function setEstExtension(bool $estExtension): self
    {
        $this->estExtension = $estExtension;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }
}
