<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Compta\Enum\Qualification;
use App\Organisation\Entity\Espace;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Qualification SPIC/SPA par équipement (Espace du socle) — décision actée « paramétrable par
 * équipement » (point EXPERT #1, §4.1 spec). Seul `SelecteurReferentielPublic` lit cette valeur pour
 * choisir M4 vs M57 ; défaut SPA si non qualifié (`ParametresRegime::qualificationParDefaut`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_qualification_equipement')]
#[ORM\UniqueConstraint(name: 'uniq_qualif_espace', columns: ['espace_id'])]
#[ApiResource(
    shortName: 'QualificationEquipement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(security: "is_granted('PERM', 'compta.gerer')"),
        new Patch(security: "is_granted('PERM', 'compta.gerer')"),
    ],
    normalizationContext: ['groups' => ['qualif:read']],
    denormalizationContext: ['groups' => ['qualif:write']],
)]
class QualificationEquipement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['qualif:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Espace::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Assert\NotNull]
    #[Groups(['qualif:read', 'qualif:write'])]
    private ?Espace $espace = null;

    #[ORM\Column(length: 4, enumType: Qualification::class)]
    #[Assert\NotNull]
    #[Groups(['qualif:read', 'qualif:write'])]
    private Qualification $qualification = Qualification::Spa;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getQualification(): Qualification
    {
        return $this->qualification;
    }

    public function setQualification(Qualification $qualification): self
    {
        $this->qualification = $qualification;

        return $this;
    }
}
