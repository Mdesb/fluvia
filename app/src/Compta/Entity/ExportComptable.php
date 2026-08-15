<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Compta\Enum\FormatExport;
use App\Compta\Enum\StatutExport;
use App\Compta\State\GenererExportProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Export comptable commuté par profil (RG-EXPORT-07, US-L4-07). Borné à l'exercice, écritures
 * validées uniquement ; bloqué avec liste d'anomalies en cas d'échec de contrôle (CA-10/CA-11).
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_export_comptable')]
#[ApiResource(
    shortName: 'ExportComptable',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(
            security: "is_granted('PERM', 'compta.exporter')",
            processor: GenererExportProcessor::class,
        ),
        new Get(
            uriTemplate: '/compta/exports/{id}/telecharger',
            security: "is_granted('PERM', 'compta.exporter')",
            normalizationContext: ['groups' => ['export:read', 'export:telecharger']],
        ),
    ],
    normalizationContext: ['groups' => ['export:read']],
    denormalizationContext: ['groups' => ['export:write']],
)]
class ExportComptable
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['export:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['export:read', 'export:write'])]
    private ?ProfilExploitant $profilExploitant = null;

    #[ORM\Column(length: 20, enumType: FormatExport::class)]
    #[Groups(['export:read', 'export:write'])]
    private FormatExport $format = FormatExport::Fec;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['export:read', 'export:write'])]
    private \DateTimeImmutable $periodeDebut;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['export:read', 'export:write'])]
    private \DateTimeImmutable $periodeFin;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['export:read', 'export:write'])]
    private bool $planifie = false;

    #[ORM\Column(length: 16, nullable: true)]
    #[Groups(['export:read', 'export:write'])]
    private ?string $frequence = null;

    #[ORM\Column(length: 160, nullable: true)]
    #[Groups(['export:read', 'export:write'])]
    private ?string $destinataire = null;

    #[ORM\Column(length: 16, enumType: StatutExport::class, options: ['default' => 'genere'])]
    #[Groups(['export:read'])]
    private StatutExport $statut = StatutExport::Genere;

    /** @var list<string>|null */
    #[ORM\Column(nullable: true)]
    #[Groups(['export:read'])]
    private ?array $anomalies = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['export:read', 'export:write'])]
    private bool $genereTitreRegularisation = false;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['export:telecharger'])]
    private ?string $contenu = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->periodeDebut = new \DateTimeImmutable('first day of this year');
        $this->periodeFin = new \DateTimeImmutable('last day of this year');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProfilExploitant(): ?ProfilExploitant
    {
        return $this->profilExploitant;
    }

    public function setProfilExploitant(?ProfilExploitant $profilExploitant): self
    {
        $this->profilExploitant = $profilExploitant;

        return $this;
    }

    public function getEtablissement(): ?\App\Organisation\Entity\Etablissement
    {
        return $this->profilExploitant?->getEtablissementPrincipal();
    }

    public function getFormat(): FormatExport
    {
        return $this->format;
    }

    public function setFormat(FormatExport $format): self
    {
        $this->format = $format;

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

    public function isPlanifie(): bool
    {
        return $this->planifie;
    }

    public function setPlanifie(bool $planifie): self
    {
        $this->planifie = $planifie;

        return $this;
    }

    public function getFrequence(): ?string
    {
        return $this->frequence;
    }

    public function setFrequence(?string $frequence): self
    {
        $this->frequence = $frequence;

        return $this;
    }

    public function getDestinataire(): ?string
    {
        return $this->destinataire;
    }

    public function setDestinataire(?string $destinataire): self
    {
        $this->destinataire = $destinataire;

        return $this;
    }

    public function getStatut(): StatutExport
    {
        return $this->statut;
    }

    public function setStatut(StatutExport $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    /** @return list<string>|null */
    public function getAnomalies(): ?array
    {
        return $this->anomalies;
    }

    /** @param list<string>|null $anomalies */
    public function setAnomalies(?array $anomalies): self
    {
        $this->anomalies = $anomalies;

        return $this;
    }

    public function isGenereTitreRegularisation(): bool
    {
        return $this->genereTitreRegularisation;
    }

    public function setGenereTitreRegularisation(bool $genereTitreRegularisation): self
    {
        $this->genereTitreRegularisation = $genereTitreRegularisation;

        return $this;
    }

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

    public function setContenu(?string $contenu): self
    {
        $this->contenu = $contenu;

        return $this;
    }
}
