<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Crm\Enum\PorteeFusion;
use App\Crm\Enum\StatutJournalFusion;
use App\Crm\State\DefusionnerProcessor;
use App\Crm\State\FusionnerProcessor;
use App\Crm\State\PrevisualiserFusionProvider;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Journal de fusion (RG-M4-06, US-L5-08) : trace intégralement (qui/quand/quoi) et permet la
 * défusion (restauration depuis `snapshotAvant`). **Append-only**, sauf la transition de défusion
 * (garde `InalterabiliteCrmListener`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_journal_fusion')]
#[ApiResource(
    shortName: 'JournalFusion',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'crm.fusionner')"),
        new Get(security: "is_granted('PERM', 'crm.fusionner')"),
        new Get(
            uriTemplate: '/crm/fusions/previsualiser',
            security: "is_granted('PERM', 'crm.fusionner')",
            provider: PrevisualiserFusionProvider::class,
        ),
        new Post(
            uriTemplate: '/crm/fusions',
            read: false,
            input: false,
            security: "is_granted('PERM', 'crm.fusionner')",
            processor: FusionnerProcessor::class,
        ),
        new Post(
            uriTemplate: '/crm/fusions/{id}/defusionner',
            read: true,
            input: false,
            security: "is_granted('PERM', 'crm.fusionner')",
            processor: DefusionnerProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['fusion:read']],
)]
class JournalFusion
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['fusion:read'])]
    private Uuid $id;

    #[ORM\Column(length: 12, enumType: PorteeFusion::class)]
    #[Groups(['fusion:read'])]
    private PorteeFusion $portee;

    /** @var list<string> UUID des fiches (Client ou Famille selon `portee`). */
    #[ORM\Column]
    #[Groups(['fusion:read'])]
    private array $fichesSources = [];

    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['fusion:read'])]
    private Uuid $ficheSurvivante;

    /** @var array<string, mixed> map(champ => valeur retenue) */
    #[ORM\Column]
    #[Groups(['fusion:read'])]
    private array $champsArbitres = [];

    /** @var array<string, mixed> état complet des fiches sources avant fusion (restauration CA-14). */
    #[ORM\Column]
    private array $snapshotAvant = [];

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['fusion:read'])]
    private ?string $motif = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['fusion:read'])]
    private ?Utilisateur $effectuePar = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['fusion:read'])]
    private \DateTimeImmutable $dateFusion;

    #[ORM\Column(length: 16, enumType: StatutJournalFusion::class, options: ['default' => 'active'])]
    #[Groups(['fusion:read'])]
    private StatutJournalFusion $statut = StatutJournalFusion::Active;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['fusion:read'])]
    private ?\DateTimeImmutable $dateDefusion = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['fusion:read'])]
    private ?Utilisateur $defusionnePar = null;

    public function __construct(PorteeFusion $portee = PorteeFusion::Client)
    {
        $this->id = Uuid::v4();
        $this->portee = $portee;
        $this->ficheSurvivante = Uuid::v4();
        $this->dateFusion = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPortee(): PorteeFusion
    {
        return $this->portee;
    }

    public function setPortee(PorteeFusion $portee): self
    {
        $this->portee = $portee;

        return $this;
    }

    /** @return list<string> */
    public function getFichesSources(): array
    {
        return $this->fichesSources;
    }

    /** @param list<string> $fichesSources */
    public function setFichesSources(array $fichesSources): self
    {
        $this->fichesSources = $fichesSources;

        return $this;
    }

    public function getFicheSurvivante(): Uuid
    {
        return $this->ficheSurvivante;
    }

    public function setFicheSurvivante(Uuid $ficheSurvivante): self
    {
        $this->ficheSurvivante = $ficheSurvivante;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getChampsArbitres(): array
    {
        return $this->champsArbitres;
    }

    /** @param array<string, mixed> $champsArbitres */
    public function setChampsArbitres(array $champsArbitres): self
    {
        $this->champsArbitres = $champsArbitres;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSnapshotAvant(): array
    {
        return $this->snapshotAvant;
    }

    /** @param array<string, mixed> $snapshotAvant */
    public function setSnapshotAvant(array $snapshotAvant): self
    {
        $this->snapshotAvant = $snapshotAvant;

        return $this;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getEffectuePar(): ?Utilisateur
    {
        return $this->effectuePar;
    }

    public function setEffectuePar(?Utilisateur $effectuePar): self
    {
        $this->effectuePar = $effectuePar;

        return $this;
    }

    public function getDateFusion(): \DateTimeImmutable
    {
        return $this->dateFusion;
    }

    public function getStatut(): StatutJournalFusion
    {
        return $this->statut;
    }

    public function setStatut(StatutJournalFusion $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDateDefusion(): ?\DateTimeImmutable
    {
        return $this->dateDefusion;
    }

    public function setDateDefusion(?\DateTimeImmutable $dateDefusion): self
    {
        $this->dateDefusion = $dateDefusion;

        return $this;
    }

    public function getDefusionnePar(): ?Utilisateur
    {
        return $this->defusionnePar;
    }

    public function setDefusionnePar(?Utilisateur $defusionnePar): self
    {
        $this->defusionnePar = $defusionnePar;

        return $this;
    }
}
