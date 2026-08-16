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
use App\Reporting\Enum\ModeCalculIndicateur;
use App\Reporting\Enum\NatureIndicateur;
use App\Reporting\Enum\SourceModuleIndicateur;
use App\Reporting\Enum\UniteIndicateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Référentiel des indicateurs (§1.3 plan-reporting.md, RG-M7-02). Porte le libellé explicite
 * obligatoire pour toute variante FMI agrégée (RG-M7-04, §2.4/§2.5) — `FMI_MAX_SOMME_SITES` /
 * `FMI_MAX_SITE_CRITIQUE` sont des codes distincts, jamais une simple convention d'affichage.
 * Jamais supprimé si référencé (cas limite spec §7) : seule `Patch(actif=false)` désactive.
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_indicateur')]
#[ORM\UniqueConstraint(name: 'uniq_indicateur_code', columns: ['code'])]
#[ApiResource(
    shortName: 'Indicateur',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reporting.lire')"),
        new Get(security: "is_granted('PERM', 'reporting.lire')"),
        new Post(security: "is_granted('PERM', 'reporting.configurer')"),
        new Patch(security: "is_granted('PERM', 'reporting.configurer')"),
    ],
    normalizationContext: ['groups' => ['indicateur:read']],
    denormalizationContext: ['groups' => ['indicateur:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['code' => 'exact', 'sourceModule' => 'exact', 'actif' => 'exact'])]
class Indicateur
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['indicateur:read', 'mesure:read', 'objectif:read', 'tdb:read'])]
    private Uuid $id;

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    #[Groups(['indicateur:read', 'indicateur:write', 'mesure:read', 'objectif:read', 'tdb:read'])]
    private string $code = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['indicateur:read', 'indicateur:write', 'mesure:read'])]
    private string $libelle = '';

    #[ORM\Column(length: 20, enumType: UniteIndicateur::class)]
    #[Assert\NotNull]
    #[Groups(['indicateur:read', 'indicateur:write', 'mesure:read'])]
    private UniteIndicateur $unite = UniteIndicateur::Nombre;

    #[ORM\Column(length: 12, enumType: ModeCalculIndicateur::class)]
    #[Assert\NotNull]
    #[Groups(['indicateur:read', 'indicateur:write'])]
    private ModeCalculIndicateur $modeCalcul = ModeCalculIndicateur::Somme;

    #[ORM\Column(length: 12, enumType: NatureIndicateur::class)]
    #[Assert\NotNull]
    #[Groups(['indicateur:read', 'indicateur:write'])]
    private NatureIndicateur $nature = NatureIndicateur::Cumule;

    #[ORM\Column(length: 16, enumType: SourceModuleIndicateur::class)]
    #[Assert\NotNull]
    #[Groups(['indicateur:read', 'indicateur:write'])]
    private SourceModuleIndicateur $sourceModule = SourceModuleIndicateur::Vente;

    /** RG-REPORT-11 : ancienneté max tolérée (minutes) avant marquage `partiel`. */
    #[ORM\Column(nullable: true, options: ['default' => 60])]
    #[Groups(['indicateur:read', 'indicateur:write'])]
    private ?int $seuilCompletudeMinutes = 60;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['indicateur:read', 'indicateur:write'])]
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

    public function getUnite(): UniteIndicateur
    {
        return $this->unite;
    }

    public function setUnite(UniteIndicateur $unite): self
    {
        $this->unite = $unite;

        return $this;
    }

    public function getModeCalcul(): ModeCalculIndicateur
    {
        return $this->modeCalcul;
    }

    public function setModeCalcul(ModeCalculIndicateur $modeCalcul): self
    {
        $this->modeCalcul = $modeCalcul;

        return $this;
    }

    public function getNature(): NatureIndicateur
    {
        return $this->nature;
    }

    public function setNature(NatureIndicateur $nature): self
    {
        $this->nature = $nature;

        return $this;
    }

    public function getSourceModule(): SourceModuleIndicateur
    {
        return $this->sourceModule;
    }

    public function setSourceModule(SourceModuleIndicateur $sourceModule): self
    {
        $this->sourceModule = $sourceModule;

        return $this;
    }

    public function getSeuilCompletudeMinutes(): ?int
    {
        return $this->seuilCompletudeMinutes;
    }

    public function setSeuilCompletudeMinutes(?int $seuilCompletudeMinutes): self
    {
        $this->seuilCompletudeMinutes = $seuilCompletudeMinutes;

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
