<?php

declare(strict_types=1);

namespace App\Reporting\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Reporting\Enum\EtatRapportPlanifie;
use App\Reporting\Enum\FormatExport;
use App\Reporting\Enum\PeriodiciteRapport;
use App\Reporting\State\RapportPlanifieProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Rapport planifié (§1.7 plan-reporting.md, RG-M7-06) : référence un `TableauDeBord` existant,
 * n'invente pas d'indicateur (RG-M7-02). Écriture (création/modification, y compris les
 * `DestinataireRapport`) entièrement portée par `RapportPlanifieProcessor` — corps JSON manuel (§2.3
 * plan-reporting.md), même patron que `CreerVenteProcessor` (input:false) : le contrôle « destinataires
 * ⊆ périmètre créateur » (422 sinon) dépend d'un calcul ensembliste incompatible avec une simple
 * dénormalisation de collection imbriquée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_rapport_planifie')]
#[ApiResource(
    shortName: 'RapportPlanifie',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reporting.lire') and is_granted('PERM', 'reporting.planifier')"),
        new Get(security: "is_granted('PERM', 'reporting.lire') and is_granted('PERM', 'reporting.planifier')"),
        new Post(
            uriTemplate: '/rapport_planifies',
            read: false,
            input: false,
            security: "is_granted('PERM', 'reporting.planifier')",
            processor: RapportPlanifieProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'reporting.planifier')",
            read: false,
            input: false,
            processor: RapportPlanifieProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['rapport:read']],
)]
class RapportPlanifie
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['rapport:read'])]
    private Uuid $id;

    #[ORM\Column(length: 160)]
    #[Groups(['rapport:read'])]
    private string $nom = '';

    #[ORM\ManyToOne(targetEntity: TableauDeBord::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['rapport:read'])]
    private ?TableauDeBord $tableauDeBord = null;

    #[ORM\Column(length: 4, enumType: FormatExport::class)]
    #[Groups(['rapport:read'])]
    private FormatExport $format = FormatExport::Csv;

    #[ORM\Column(length: 12, enumType: PeriodiciteRapport::class)]
    #[Groups(['rapport:read'])]
    private PeriodiciteRapport $periodicite = PeriodiciteRapport::Quotidienne;

    #[ORM\Column(length: 5)]
    #[Groups(['rapport:read'])]
    private string $heureEnvoi = '07:00';

    #[ORM\Column(length: 9, enumType: EtatRapportPlanifie::class, options: ['default' => 'actif'])]
    #[Groups(['rapport:read'])]
    private EtatRapportPlanifie $etat = EtatRapportPlanifie::Actif;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['rapport:read'])]
    private ?Utilisateur $createur = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['rapport:read'])]
    private ?\DateTimeImmutable $dernierEnvoi = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['rapport:read'])]
    private ?\DateTimeImmutable $prochainEnvoi = null;

    /** @var Collection<int, DestinataireRapport> */
    #[ORM\OneToMany(targetEntity: DestinataireRapport::class, mappedBy: 'rapportPlanifie', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['rapport:read'])]
    private Collection $destinataires;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->destinataires = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getTableauDeBord(): ?TableauDeBord
    {
        return $this->tableauDeBord;
    }

    public function setTableauDeBord(?TableauDeBord $tableauDeBord): self
    {
        $this->tableauDeBord = $tableauDeBord;

        return $this;
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

    public function getPeriodicite(): PeriodiciteRapport
    {
        return $this->periodicite;
    }

    public function setPeriodicite(PeriodiciteRapport $periodicite): self
    {
        $this->periodicite = $periodicite;

        return $this;
    }

    public function getHeureEnvoi(): string
    {
        return $this->heureEnvoi;
    }

    public function setHeureEnvoi(string $heureEnvoi): self
    {
        $this->heureEnvoi = $heureEnvoi;

        return $this;
    }

    public function getEtat(): EtatRapportPlanifie
    {
        return $this->etat;
    }

    public function setEtat(EtatRapportPlanifie $etat): self
    {
        $this->etat = $etat;

        return $this;
    }

    public function getCreateur(): ?Utilisateur
    {
        return $this->createur;
    }

    public function setCreateur(?Utilisateur $createur): self
    {
        $this->createur = $createur;

        return $this;
    }

    public function getDernierEnvoi(): ?\DateTimeImmutable
    {
        return $this->dernierEnvoi;
    }

    public function setDernierEnvoi(?\DateTimeImmutable $dernierEnvoi): self
    {
        $this->dernierEnvoi = $dernierEnvoi;

        return $this;
    }

    public function getProchainEnvoi(): ?\DateTimeImmutable
    {
        return $this->prochainEnvoi;
    }

    public function setProchainEnvoi(?\DateTimeImmutable $prochainEnvoi): self
    {
        $this->prochainEnvoi = $prochainEnvoi;

        return $this;
    }

    /** @return Collection<int, DestinataireRapport> */
    public function getDestinataires(): Collection
    {
        return $this->destinataires;
    }

    public function addDestinataire(DestinataireRapport $destinataire): self
    {
        if (!$this->destinataires->contains($destinataire)) {
            $this->destinataires->add($destinataire);
            $destinataire->setRapportPlanifie($this);
        }

        return $this;
    }

    public function removeDestinataire(DestinataireRapport $destinataire): self
    {
        $this->destinataires->removeElement($destinataire);

        return $this;
    }

    public function estActif(): bool
    {
        return $this->etat === EtatRapportPlanifie::Actif;
    }
}
