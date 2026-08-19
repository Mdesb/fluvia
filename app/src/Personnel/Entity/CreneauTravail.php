<?php

declare(strict_types=1);

namespace App\Personnel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Personnel\Enum\MotifRecurrenceTravail;
use App\Personnel\Enum\StatutCreneauTravail;
use App\Personnel\Enum\TypeQualification;
use App\Personnel\State\AnnulerCreneauTravailProcessor;
use App\Personnel\State\CreerCreneauTravailProcessor;
use App\Personnel\Validator as PersonnelAssert;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Créneau de travail — shift (RG-PERSO-03, CA-6) : plage horaire rattachée à un Établissement et,
 * le cas échéant, un Espace ; porte un libellé de poste, un effectif requis et, le cas échéant, une
 * qualification exigée. Refuse à l'enregistrement toute topologie incohérente
 * (`TopologieTravailCoherente`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'personnel_creneau_travail')]
#[ApiResource(
    shortName: 'CreneauTravail',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'personnel.lire') or is_granted('PERM', 'personnel.lire_soi')"),
        new Get(security: "is_granted('PERM', 'personnel.lire') or is_granted('PERM', 'personnel.lire_soi')"),
        new Post(
            uriTemplate: '/personnel/creneaux-travail',
            read: false,
            input: false,
            security: "is_granted('PERM', 'personnel.gerer_planning')",
            processor: CreerCreneauTravailProcessor::class,
        ),
        new Patch(security: "is_granted('PERM', 'personnel.gerer_planning')"),
        new Post(
            uriTemplate: '/personnel/creneaux-travail/{id}/annuler',
            read: true,
            input: false,
            security: "is_granted('PERM', 'personnel.gerer_planning')",
            processor: AnnulerCreneauTravailProcessor::class,
            normalizationContext: ['groups' => ['creneau_travail:read']],
        ),
    ],
    normalizationContext: ['groups' => ['creneau_travail:read']],
    denormalizationContext: ['groups' => ['creneau_travail:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['etablissement' => 'exact', 'espace' => 'exact', 'statut' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['debut'])]
#[PersonnelAssert\TopologieTravailCoherente]
class CreneauTravail
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['creneau_travail:read', 'affectation_travail:read', 'roster:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['creneau_travail:read', 'creneau_travail:write', 'roster:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\ManyToOne(targetEntity: Espace::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['creneau_travail:read', 'creneau_travail:write', 'roster:read'])]
    private ?Espace $espace = null;

    /** Référence logique vers `App\Reservation\Entity\Creneau` (§4.9 spec) — pas de FK dure. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['creneau_travail:read', 'creneau_travail:write'])]
    private ?Uuid $creneauReservationRef = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['creneau_travail:read', 'creneau_travail:write', 'roster:read'])]
    private string $libellePoste = '';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Assert\NotNull]
    #[Groups(['creneau_travail:read', 'creneau_travail:write', 'affectation_travail:read', 'roster:read'])]
    private ?\DateTimeImmutable $debut = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Assert\NotNull]
    #[Groups(['creneau_travail:read', 'creneau_travail:write', 'affectation_travail:read', 'roster:read'])]
    private ?\DateTimeImmutable $fin = null;

    #[ORM\Column(length: 10, enumType: TypeQualification::class, nullable: true)]
    #[Groups(['creneau_travail:read', 'creneau_travail:write', 'roster:read'])]
    private ?TypeQualification $qualificationRequise = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    #[Assert\GreaterThanOrEqual(1)]
    #[Groups(['creneau_travail:read', 'creneau_travail:write', 'roster:read'])]
    private int $effectifRequis = 1;

    #[ORM\Column(length: 10, enumType: StatutCreneauTravail::class, options: ['default' => 'planifie'])]
    #[Groups(['creneau_travail:read', 'roster:read'])]
    private StatutCreneauTravail $statut = StatutCreneauTravail::Planifie;

    #[ORM\Column(length: 16, enumType: MotifRecurrenceTravail::class, nullable: true)]
    #[Groups(['creneau_travail:read', 'creneau_travail:write'])]
    private ?MotifRecurrenceTravail $motifRecurrence = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['creneau_travail:read', 'creneau_travail:write'])]
    private ?\DateTimeImmutable $finRecurrence = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    public function getEspace(): ?Espace
    {
        return $this->espace;
    }

    public function setEspace(?Espace $espace): self
    {
        $this->espace = $espace;

        return $this;
    }

    public function getCreneauReservationRef(): ?Uuid
    {
        return $this->creneauReservationRef;
    }

    public function setCreneauReservationRef(?Uuid $creneauReservationRef): self
    {
        $this->creneauReservationRef = $creneauReservationRef;

        return $this;
    }

    public function getLibellePoste(): string
    {
        return $this->libellePoste;
    }

    public function setLibellePoste(string $libellePoste): self
    {
        $this->libellePoste = $libellePoste;

        return $this;
    }

    public function getDebut(): ?\DateTimeImmutable
    {
        return $this->debut;
    }

    public function setDebut(?\DateTimeImmutable $debut): self
    {
        $this->debut = $debut;

        return $this;
    }

    public function getFin(): ?\DateTimeImmutable
    {
        return $this->fin;
    }

    public function setFin(?\DateTimeImmutable $fin): self
    {
        $this->fin = $fin;

        return $this;
    }

    public function getQualificationRequise(): ?TypeQualification
    {
        return $this->qualificationRequise;
    }

    public function setQualificationRequise(?TypeQualification $qualificationRequise): self
    {
        $this->qualificationRequise = $qualificationRequise;

        return $this;
    }

    public function getEffectifRequis(): int
    {
        return $this->effectifRequis;
    }

    public function setEffectifRequis(int $effectifRequis): self
    {
        $this->effectifRequis = $effectifRequis;

        return $this;
    }

    public function getStatut(): StatutCreneauTravail
    {
        return $this->statut;
    }

    public function setStatut(StatutCreneauTravail $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getMotifRecurrence(): ?MotifRecurrenceTravail
    {
        return $this->motifRecurrence;
    }

    public function setMotifRecurrence(?MotifRecurrenceTravail $motifRecurrence): self
    {
        $this->motifRecurrence = $motifRecurrence;

        return $this;
    }

    public function getFinRecurrence(): ?\DateTimeImmutable
    {
        return $this->finRecurrence;
    }

    public function setFinRecurrence(?\DateTimeImmutable $finRecurrence): self
    {
        $this->finRecurrence = $finRecurrence;

        return $this;
    }
}
