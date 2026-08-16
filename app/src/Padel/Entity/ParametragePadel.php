<?php

declare(strict_types=1);

namespace App\Padel\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Organisation\Entity\Etablissement;
use App\Padel\Enum\ModeRepartitionSurcout;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Paramétrage padel d'un établissement (1:1, patron `Sport\Entity\PolitiqueAntiImpayes`). Porte
 * l'échelle de niveau, le mode de répartition du surcoût « maintenue à 3 » (décision n°4), la
 * tolérance d'entrée badge, et les références catalogue M1 (ancres, résolues logiquement).
 */
#[ORM\Entity]
#[ORM\Table(name: 'padel_parametrage_etablissement')]
#[ApiResource(
    shortName: 'PadelParametrage',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')"),
        new Get(security: "is_granted('PERM', 'padel.lire')"),
        new Patch(security: "is_granted('PERM', 'padel.parametrer')"),
    ],
    normalizationContext: ['groups' => ['parametrage:read']],
    denormalizationContext: ['groups' => ['parametrage:write']],
)]
class ParametragePadel
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['parametrage:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Groups(['parametrage:read', 'parametrage:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['parametrage:read', 'parametrage:write'])]
    private ?Uuid $produitTerrainRef = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['parametrage:read', 'parametrage:write'])]
    private ?Uuid $typeTarifMembreRef = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['parametrage:read', 'parametrage:write'])]
    private ?Uuid $typeTarifNonMembreRef = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    #[Groups(['parametrage:read', 'parametrage:write'])]
    private int $echelleNiveauMin = 1;

    #[ORM\Column(type: 'smallint', options: ['default' => 10])]
    #[Groups(['parametrage:read', 'parametrage:write'])]
    private int $echelleNiveauMax = 10;

    #[ORM\Column(length: 20, enumType: ModeRepartitionSurcout::class, options: ['default' => 'equitable_presents'])]
    #[Groups(['parametrage:read', 'parametrage:write'])]
    private ModeRepartitionSurcout $modeRepartitionSurcout = ModeRepartitionSurcout::EquitablePresents;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['parametrage:read', 'parametrage:write'])]
    private ?int $toleranceEntreeBadgeMinutes = 15;

    /** Majoration forfaitaire appliquée quand la réservation est « avec coach » (§4.8, US-PADEL-09). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['parametrage:read', 'parametrage:write'])]
    private string $majorationCoachMontant = '0.00';

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

    public function getProduitTerrainRef(): ?Uuid
    {
        return $this->produitTerrainRef;
    }

    public function setProduitTerrainRef(?Uuid $produitTerrainRef): self
    {
        $this->produitTerrainRef = $produitTerrainRef;

        return $this;
    }

    public function getTypeTarifMembreRef(): ?Uuid
    {
        return $this->typeTarifMembreRef;
    }

    public function setTypeTarifMembreRef(?Uuid $typeTarifMembreRef): self
    {
        $this->typeTarifMembreRef = $typeTarifMembreRef;

        return $this;
    }

    public function getTypeTarifNonMembreRef(): ?Uuid
    {
        return $this->typeTarifNonMembreRef;
    }

    public function setTypeTarifNonMembreRef(?Uuid $typeTarifNonMembreRef): self
    {
        $this->typeTarifNonMembreRef = $typeTarifNonMembreRef;

        return $this;
    }

    public function getEchelleNiveauMin(): int
    {
        return $this->echelleNiveauMin;
    }

    public function setEchelleNiveauMin(int $echelleNiveauMin): self
    {
        $this->echelleNiveauMin = $echelleNiveauMin;

        return $this;
    }

    public function getEchelleNiveauMax(): int
    {
        return $this->echelleNiveauMax;
    }

    public function setEchelleNiveauMax(int $echelleNiveauMax): self
    {
        $this->echelleNiveauMax = $echelleNiveauMax;

        return $this;
    }

    public function getModeRepartitionSurcout(): ModeRepartitionSurcout
    {
        return $this->modeRepartitionSurcout;
    }

    public function setModeRepartitionSurcout(ModeRepartitionSurcout $modeRepartitionSurcout): self
    {
        $this->modeRepartitionSurcout = $modeRepartitionSurcout;

        return $this;
    }

    public function getToleranceEntreeBadgeMinutes(): ?int
    {
        return $this->toleranceEntreeBadgeMinutes;
    }

    public function setToleranceEntreeBadgeMinutes(?int $toleranceEntreeBadgeMinutes): self
    {
        $this->toleranceEntreeBadgeMinutes = $toleranceEntreeBadgeMinutes;

        return $this;
    }

    public function getMajorationCoachMontant(): string
    {
        return $this->majorationCoachMontant;
    }

    public function setMajorationCoachMontant(string $majorationCoachMontant): self
    {
        $this->majorationCoachMontant = $majorationCoachMontant;

        return $this;
    }
}
