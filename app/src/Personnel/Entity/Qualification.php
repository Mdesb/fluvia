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
use App\Personnel\Enum\TypeQualification;
use App\Personnel\Validator as PersonnelAssert;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Qualification d'un Employé (RG-PERSO-02, CA-3) : type + date de validité. Le statut
 * (valide/expirée) n'est **pas persisté** (décision n°10 du plan) — calculé à la volée par
 * `estValideA()`, même patron que `Piscine\QualificationEncadrant::estValideA()`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'personnel_qualification')]
#[ApiResource(
    shortName: 'Qualification',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'personnel.lire') or is_granted('PERM', 'personnel.lire_soi')"),
        new Get(security: "is_granted('PERM', 'personnel.lire') or is_granted('EMPLOYE_SOI', object.getEmploye())"),
        new Post(security: "is_granted('PERM', 'personnel.gerer_qualification')"),
        new Patch(security: "is_granted('PERM', 'personnel.gerer_qualification')"),
    ],
    normalizationContext: ['groups' => ['qualification:read']],
    denormalizationContext: ['groups' => ['qualification:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['employe' => 'exact', 'type' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['dateValidite'])]
#[PersonnelAssert\QualificationLibelleCoherent]
class Qualification
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['qualification:read', 'affectation_travail:read', 'roster:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Employe::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['qualification:read', 'qualification:write'])]
    private ?Employe $employe = null;

    #[ORM\Column(length: 10, enumType: TypeQualification::class)]
    #[Assert\NotNull]
    #[Groups(['qualification:read', 'qualification:write', 'affectation_travail:read', 'roster:read'])]
    private ?TypeQualification $type = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['qualification:read', 'qualification:write'])]
    private ?string $libelle = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['qualification:read', 'qualification:write'])]
    private ?\DateTimeImmutable $dateObtention = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['qualification:read', 'qualification:write', 'affectation_travail:read', 'roster:read'])]
    private ?\DateTimeImmutable $dateValidite = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmploye(): ?Employe
    {
        return $this->employe;
    }

    public function setEmploye(?Employe $employe): self
    {
        $this->employe = $employe;

        return $this;
    }

    public function getType(): ?TypeQualification
    {
        return $this->type;
    }

    public function setType(?TypeQualification $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(?string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getDateObtention(): ?\DateTimeImmutable
    {
        return $this->dateObtention;
    }

    public function setDateObtention(?\DateTimeImmutable $dateObtention): self
    {
        $this->dateObtention = $dateObtention;

        return $this;
    }

    public function getDateValidite(): ?\DateTimeImmutable
    {
        return $this->dateValidite;
    }

    public function setDateValidite(?\DateTimeImmutable $dateValidite): self
    {
        $this->dateValidite = $dateValidite;

        return $this;
    }

    /** Vrai si la qualification couvre encore la date donnée (CA-3, non persisté — décision n°10). */
    public function estValideA(\DateTimeImmutable $date): bool
    {
        return $this->dateValidite !== null && $this->dateValidite >= $date;
    }

    /** Représentation dérivée « valide »/« expiree », exposée en lecture (§5 spec). */
    #[Groups(['qualification:read', 'roster:read'])]
    public function getStatut(): string
    {
        return $this->estValideA(new \DateTimeImmutable()) ? 'valide' : 'expiree';
    }
}
