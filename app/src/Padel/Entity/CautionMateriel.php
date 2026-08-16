<?php

declare(strict_types=1);

namespace App\Padel\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Padel\Enum\StatutCautionMateriel;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Caution consignée à une location de matériel (US-PADEL-08, décision n°6 du plan). Suit **exactement**
 * le patron `App\Piscine\Entity\CautionCasier` : une seule caution *active* (statut ≠ libérée) par
 * location, garantie par la colonne dénormalisée `locationActive` sous contrainte unique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'padel_caution_materiel')]
#[ORM\UniqueConstraint(name: 'uniq_caution_materiel_active', columns: ['location_active'])]
#[ApiResource(
    shortName: 'PadelCautionMateriel',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')"),
        new Get(security: "is_granted('PERM', 'padel.lire')"),
    ],
    normalizationContext: ['groups' => ['caution_materiel:read']],
)]
class CautionMateriel
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['caution_materiel:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: LocationMateriel::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['caution_materiel:read'])]
    private ?LocationMateriel $location = null;

    /** Dénormalisation de `location` quand le statut n'est pas `liberee`, NULL sinon (unicité). */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $locationActive = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['caution_materiel:read'])]
    private string $montant = '0.00';

    #[ORM\Column(length: 9, enumType: StatutCautionMateriel::class, options: ['default' => 'encaissee'])]
    #[Groups(['caution_materiel:read'])]
    private StatutCautionMateriel $statut = StatutCautionMateriel::Encaissee;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['caution_materiel:read'])]
    private ?string $montantRetenu = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['caution_materiel:read'])]
    private ?\DateTimeImmutable $dateEncaissement = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['caution_materiel:read'])]
    private ?\DateTimeImmutable $dateLiberation = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLocation(): ?LocationMateriel
    {
        return $this->location;
    }

    public function setLocation(?LocationMateriel $location): self
    {
        $this->location = $location;
        $this->synchroniserLocationActive();

        return $this;
    }

    public function getLocationActive(): ?Uuid
    {
        return $this->locationActive;
    }

    public function getMontant(): string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): self
    {
        $this->montant = $montant;

        return $this;
    }

    public function getStatut(): StatutCautionMateriel
    {
        return $this->statut;
    }

    public function setStatut(StatutCautionMateriel $statut): self
    {
        $this->statut = $statut;
        $this->synchroniserLocationActive();

        return $this;
    }

    private function synchroniserLocationActive(): void
    {
        $this->locationActive = $this->statut !== StatutCautionMateriel::Liberee ? $this->location?->getId() : null;
    }

    public function getMontantRetenu(): ?string
    {
        return $this->montantRetenu;
    }

    public function setMontantRetenu(?string $montantRetenu): self
    {
        $this->montantRetenu = $montantRetenu;

        return $this;
    }

    public function getDateEncaissement(): ?\DateTimeImmutable
    {
        return $this->dateEncaissement;
    }

    public function setDateEncaissement(?\DateTimeImmutable $dateEncaissement): self
    {
        $this->dateEncaissement = $dateEncaissement;

        return $this;
    }

    public function getDateLiberation(): ?\DateTimeImmutable
    {
        return $this->dateLiberation;
    }

    public function setDateLiberation(?\DateTimeImmutable $dateLiberation): self
    {
        $this->dateLiberation = $dateLiberation;

        return $this;
    }

    public function getEtablissement(): ?\App\Organisation\Entity\Etablissement
    {
        return $this->location?->getReservation()?->getEtablissement();
    }
}
