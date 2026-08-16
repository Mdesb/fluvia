<?php

declare(strict_types=1);

namespace App\Reporting\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Reporting\Entity\Trait\RattachementNiveauInterface;
use App\Reporting\Entity\Trait\RattachementNiveauTrait;
use App\Reporting\Enum\GranulariteMesure;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Valeur cible d'un indicateur sur une période/un périmètre (§1.5 plan-reporting.md) — ajouté par
 * le plan (absent du §5 « Objets de données » de la spec) pour rendre testable CA-3 (« écart vs
 * objectifs »). CRUD `reporting.configurer` en écriture, filtré périmètre en lecture.
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_objectif_indicateur')]
#[ApiResource(
    shortName: 'ObjectifIndicateur',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reporting.lire')"),
        new Get(security: "is_granted('PERM', 'reporting.lire')"),
        new Post(security: "is_granted('PERM', 'reporting.configurer')"),
        new Patch(security: "is_granted('PERM', 'reporting.configurer')"),
        new Delete(security: "is_granted('PERM', 'reporting.configurer')"),
    ],
    normalizationContext: ['groups' => ['objectif:read']],
    denormalizationContext: ['groups' => ['objectif:write']],
)]
class ObjectifIndicateur implements RattachementNiveauInterface
{
    use RattachementNiveauTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['objectif:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Indicateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['objectif:read', 'objectif:write'])]
    private ?Indicateur $indicateur = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['objectif:read', 'objectif:write'])]
    private \DateTimeImmutable $periodeDebut;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['objectif:read', 'objectif:write'])]
    private \DateTimeImmutable $periodeFin;

    #[ORM\Column(length: 8, enumType: GranulariteMesure::class)]
    #[Assert\NotNull]
    #[Groups(['objectif:read', 'objectif:write'])]
    private GranulariteMesure $granularite = GranulariteMesure::Jour;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    #[Assert\NotNull]
    #[Groups(['objectif:read', 'objectif:write'])]
    private string $valeurCible = '0.00';

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->periodeDebut = new \DateTimeImmutable('today');
        $this->periodeFin = new \DateTimeImmutable('today');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIndicateur(): ?Indicateur
    {
        return $this->indicateur;
    }

    public function setIndicateur(?Indicateur $indicateur): self
    {
        $this->indicateur = $indicateur;

        return $this;
    }

    public function getPeriodeDebut(): \DateTimeImmutable
    {
        return $this->periodeDebut;
    }

    public function setPeriodeDebut(\DateTimeImmutable $periodeDebut): self
    {
        $this->periodeDebut = $periodeDebut;

        return $this;
    }

    public function getPeriodeFin(): \DateTimeImmutable
    {
        return $this->periodeFin;
    }

    public function setPeriodeFin(\DateTimeImmutable $periodeFin): self
    {
        $this->periodeFin = $periodeFin;

        return $this;
    }

    public function getGranularite(): GranulariteMesure
    {
        return $this->granularite;
    }

    public function setGranularite(GranulariteMesure $granularite): self
    {
        $this->granularite = $granularite;

        return $this;
    }

    public function getValeurCible(): string
    {
        return $this->valeurCible;
    }

    public function setValeurCible(string $valeurCible): self
    {
        $this->valeurCible = $valeurCible;

        return $this;
    }
}
