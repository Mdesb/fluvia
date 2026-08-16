<?php

declare(strict_types=1);

namespace App\Patinoire\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Organisation\Entity\Etablissement;
use App\Patinoire\Enum\StatutCaution;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Caution consignée à la sortie d'une paire de patins (RG-PAT-01, US-PATIN-02, plan §0 point 1).
 * Reprend **exactement** le patron `App\Piscine\Entity\CautionCasier` (montant, statut,
 * moyenEncaissement, `regieMouvementRef` en référence logique non-FK) — dupliqué volontairement, pas
 * de mutualisation dans ce lot (spec §8 point 3, Risque n°1 du plan). Une seule caution *active*
 * (statut ≠ libérée) par location : garantie par la colonne dénormalisée `locationActive`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'patin_caution_location')]
#[ORM\UniqueConstraint(name: 'uniq_caution_location_active', columns: ['location_active'])]
#[ApiResource(
    shortName: 'PatinoireCautionLocationPatins',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'patinoire.lire')"),
        new Get(security: "is_granted('PERM', 'patinoire.lire')"),
    ],
    normalizationContext: ['groups' => ['caution_location:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['location' => 'exact', 'statut' => 'exact'])]
class CautionLocationPatins
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['caution_location:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: LocationPatins::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['caution_location:read'])]
    private ?LocationPatins $location = null;

    /** Dénormalisation de `location` quand le statut n'est pas `liberee`, NULL sinon (unicité). */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $locationActive = null;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['caution_location:read'])]
    private string $montant = '0.00';

    #[ORM\Column(length: 17, enumType: StatutCaution::class, options: ['default' => 'encaissee'])]
    #[Groups(['caution_location:read'])]
    private StatutCaution $statut = StatutCaution::Encaissee;

    #[ORM\Column(length: 30, nullable: true)]
    #[Groups(['caution_location:read'])]
    private ?string $moyenEncaissement = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['caution_location:read'])]
    private ?Uuid $regieMouvementRef = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['caution_location:read'])]
    private ?\DateTimeImmutable $dateEncaissement = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['caution_location:read'])]
    private ?\DateTimeImmutable $dateLiberation = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLocation(): ?LocationPatins
    {
        return $this->location;
    }

    public function setLocation(?LocationPatins $location): self
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

    public function getStatut(): StatutCaution
    {
        return $this->statut;
    }

    public function setStatut(StatutCaution $statut): self
    {
        $this->statut = $statut;
        $this->synchroniserLocationActive();

        return $this;
    }

    private function synchroniserLocationActive(): void
    {
        $this->locationActive = $this->statut !== StatutCaution::Liberee ? $this->location?->getId() : null;
    }

    public function getMoyenEncaissement(): ?string
    {
        return $this->moyenEncaissement;
    }

    public function setMoyenEncaissement(?string $moyenEncaissement): self
    {
        $this->moyenEncaissement = $moyenEncaissement;

        return $this;
    }

    public function getRegieMouvementRef(): ?Uuid
    {
        return $this->regieMouvementRef;
    }

    public function setRegieMouvementRef(?Uuid $regieMouvementRef): self
    {
        $this->regieMouvementRef = $regieMouvementRef;

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

    public function getEtablissement(): ?Etablissement
    {
        return $this->location?->getEtablissement();
    }
}
