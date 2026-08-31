<?php

declare(strict_types=1);

namespace App\Padel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Padel\Enum\FormatTournoi;
use App\Padel\State\EstablishmentStampProcessor;
use App\Padel\Enum\StatutTournoi;
use App\Padel\State\GenererPoulesProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** Tournoi/ligue padel (US-PADEL-05/06/07). */
#[ORM\Entity]
#[ORM\Table(name: 'padel_tournoi')]
#[ApiResource(
    shortName: 'PadelTournoi',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')"),
        new Get(security: "is_granted('PERM', 'padel.lire')"),
        new Post(security: "is_granted('PERM', 'padel.tournoi_gerer')", processor: EstablishmentStampProcessor::class),
        new Patch(security: "is_granted('PERM', 'padel.tournoi_gerer')"),
        // Génère les poules et bloque les terrains nécessaires (US-PADEL-05, CA-6).
        new Post(
            uriTemplate: '/padel/tournois/{id}/generer-poules',
            read: true,
            input: false,
            security: "is_granted('PERM', 'padel.tournoi_gerer')",
            processor: GenererPoulesProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['tournoi:read']],
    denormalizationContext: ['groups' => ['tournoi:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['etablissement' => 'exact', 'statut' => 'exact'])]
class Tournoi
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['tournoi:read', 'inscription_tournoi:read', 'match_tournoi:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['tournoi:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Groups(['tournoi:read', 'tournoi:write'])]
    private string $nom = '';

    #[ORM\Column(length: 8, enumType: FormatTournoi::class)]
    #[Groups(['tournoi:read', 'tournoi:write'])]
    private FormatTournoi $format = FormatTournoi::Poules;

    #[ORM\Column(length: 60, nullable: true)]
    #[Groups(['tournoi:read', 'tournoi:write'])]
    private ?string $categorie = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['tournoi:read', 'tournoi:write'])]
    private ?int $niveauRequisMin = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['tournoi:read', 'tournoi:write'])]
    private ?int $niveauRequisMax = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['tournoi:read', 'tournoi:write'])]
    private string $fraisInscription = '0.00';

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['tournoi:read', 'tournoi:write'])]
    private \DateTimeImmutable $dateDebut;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['tournoi:read', 'tournoi:write'])]
    private \DateTimeImmutable $dateFin;

    #[ORM\Column(length: 20, enumType: StatutTournoi::class, options: ['default' => 'ouvert_inscriptions'])]
    #[Groups(['tournoi:read'])]
    private StatutTournoi $statut = StatutTournoi::OuvertInscriptions;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateDebut = new \DateTimeImmutable('today');
        $this->dateFin = new \DateTimeImmutable('today');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
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

    public function getFormat(): FormatTournoi
    {
        return $this->format;
    }

    public function setFormat(FormatTournoi $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function getCategorie(): ?string
    {
        return $this->categorie;
    }

    public function setCategorie(?string $categorie): self
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getNiveauRequisMin(): ?int
    {
        return $this->niveauRequisMin;
    }

    public function setNiveauRequisMin(?int $niveauRequisMin): self
    {
        $this->niveauRequisMin = $niveauRequisMin;

        return $this;
    }

    public function getNiveauRequisMax(): ?int
    {
        return $this->niveauRequisMax;
    }

    public function setNiveauRequisMax(?int $niveauRequisMax): self
    {
        $this->niveauRequisMax = $niveauRequisMax;

        return $this;
    }

    public function getFraisInscription(): string
    {
        return $this->fraisInscription;
    }

    public function setFraisInscription(string $fraisInscription): self
    {
        $this->fraisInscription = $fraisInscription;

        return $this;
    }

    public function getDateDebut(): \DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTimeImmutable $dateDebut): self
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): \DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(\DateTimeImmutable $dateFin): self
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getStatut(): StatutTournoi
    {
        return $this->statut;
    }

    public function setStatut(StatutTournoi $statut): self
    {
        $this->statut = $statut;

        return $this;
    }
}
