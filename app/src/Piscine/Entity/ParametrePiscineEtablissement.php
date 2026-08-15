<?php

declare(strict_types=1);

namespace App\Piscine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use App\Piscine\Enum\ModeProrata;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Paramètres piscine par établissement (1-1, plan §1.6) : centralise les points « paramétrable par
 * établissement » de la spec (délai de forçage casier, montant de caution par défaut, mode de
 * prorata par défaut) plutôt que des valeurs codées en dur (constitution §4).
 *
 * ⚠ HYPOTHÈSE — `delaiForcageCasierJours` (3 j) et `montantCautionCasierDefaut` (10 €) sont des
 * valeurs de départ non chiffrées par les sources (spec §4.9, plan risque n°5) — à ajuster avec
 * l'exploitant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'piscine_parametre_etablissement')]
#[ORM\UniqueConstraint(name: 'uniq_param_piscine_etablissement', columns: ['etablissement_id'])]
#[ApiResource(
    shortName: 'ParametrePiscineEtablissement',
    operations: [
        new Get(security: "is_granted('PERM', 'piscine.lire')"),
        new Patch(security: "is_granted('PERM', 'piscine.gerer')"),
    ],
    normalizationContext: ['groups' => ['param_piscine:read']],
    denormalizationContext: ['groups' => ['param_piscine:write']],
)]
class ParametrePiscineEtablissement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['param_piscine:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['param_piscine:read', 'param_piscine:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 3])]
    #[Assert\Positive(message: 'Le délai de forçage doit être un entier strictement positif (US-L6-09).')]
    #[Groups(['param_piscine:read', 'param_piscine:write'])]
    private int $delaiForcageCasierJours = 3;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, options: ['default' => '10.00'])]
    #[Assert\PositiveOrZero]
    #[Groups(['param_piscine:read', 'param_piscine:write'])]
    private string $montantCautionCasierDefaut = '10.00';

    #[ORM\Column(length: 10, enumType: ModeProrata::class, options: ['default' => 'lignes'])]
    #[Groups(['param_piscine:read', 'param_piscine:write'])]
    private ModeProrata $modeProrataDefaut = ModeProrata::Lignes;

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

    public function getDelaiForcageCasierJours(): int
    {
        return $this->delaiForcageCasierJours;
    }

    public function setDelaiForcageCasierJours(int $delaiForcageCasierJours): self
    {
        $this->delaiForcageCasierJours = $delaiForcageCasierJours;

        return $this;
    }

    public function getMontantCautionCasierDefaut(): string
    {
        return $this->montantCautionCasierDefaut;
    }

    public function setMontantCautionCasierDefaut(string $montantCautionCasierDefaut): self
    {
        $this->montantCautionCasierDefaut = $montantCautionCasierDefaut;

        return $this;
    }

    public function getModeProrataDefaut(): ModeProrata
    {
        return $this->modeProrataDefaut;
    }

    public function setModeProrataDefaut(ModeProrata $modeProrataDefaut): self
    {
        $this->modeProrataDefaut = $modeProrataDefaut;

        return $this;
    }
}
