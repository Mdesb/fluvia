<?php

declare(strict_types=1);

namespace App\Piscine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Piscine\Enum\TypeEncadrement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Qualification MNS/BNSSA d'un encadrant (RG-PISC-02, US-L6-04). Rattachée à un `Utilisateur` du
 * socle (⚠ HYPOTHÈSE retenue, spec §3/§8 point ouvert n°6). Une qualification expirée n'est plus
 * prise en compte par `ValiderCreneauBassinHandler` (calcul, pas de suppression).
 */
#[ORM\Entity]
#[ORM\Table(name: 'piscine_qualification_encadrant')]
#[ApiResource(
    shortName: 'QualificationEncadrant',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'piscine.lire')"),
        new Get(security: "is_granted('PERM', 'piscine.lire')"),
        new Post(security: "is_granted('PERM', 'piscine.gerer')"),
        new Patch(security: "is_granted('PERM', 'piscine.gerer')"),
    ],
    normalizationContext: ['groups' => ['qualif:read']],
    denormalizationContext: ['groups' => ['qualif:write']],
)]
class QualificationEncadrant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['qualif:read', 'affect:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['qualif:read', 'qualif:write', 'affect:read'])]
    private ?Utilisateur $encadrant = null;

    #[ORM\Column(length: 8, enumType: TypeEncadrement::class)]
    #[Assert\NotNull]
    #[Groups(['qualif:read', 'qualif:write', 'affect:read'])]
    private ?TypeEncadrement $type = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['qualif:read', 'qualif:write', 'affect:read'])]
    private ?\DateTimeImmutable $dateValidite = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['qualif:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEncadrant(): ?Utilisateur
    {
        return $this->encadrant;
    }

    public function setEncadrant(?Utilisateur $encadrant): self
    {
        $this->encadrant = $encadrant;

        return $this;
    }

    public function getType(): ?TypeEncadrement
    {
        return $this->type;
    }

    public function setType(?TypeEncadrement $type): self
    {
        $this->type = $type;

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

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
    }

    /** Vrai si la qualification couvre encore la date donnée (CA-4). */
    public function estValideA(\DateTimeImmutable $date): bool
    {
        return $this->dateValidite !== null && $this->dateValidite >= $date;
    }
}
