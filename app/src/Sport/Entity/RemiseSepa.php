<?php

declare(strict_types=1);

namespace App\Sport\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Sport\Enum\StatutRemiseSepa;
use App\Sport\State\GenererRemiseSepaProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/** Lot de prélèvements (pain.008 simulé, §1.5/§2.1 du plan). Génération via `CollecteurSepaInterface`. */
#[ORM\Entity]
#[ORM\Table(name: 'sport_remise_sepa')]
#[ORM\UniqueConstraint(name: 'uniq_remise_reference', columns: ['reference_remise'])]
#[ApiResource(
    shortName: 'RemiseSepa',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire') or is_granted('PERM', 'sport.piloter_impayes')"),
        new Get(security: "is_granted('PERM', 'compta.lire') or is_granted('PERM', 'sport.piloter_impayes')"),
        new Post(
            uriTemplate: '/sport/remises/generer',
            read: false,
            input: false,
            security: "is_granted('PERM', 'sport.parametrer') or is_granted('PERM', 'sport.piloter_impayes')",
            processor: GenererRemiseSepaProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['remise:read']],
)]
class RemiseSepa
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['remise:read', 'echeance:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['remise:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 35, unique: true, nullable: true)]
    #[Groups(['remise:read'])]
    private ?string $referenceRemise = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['remise:read'])]
    private \DateTimeImmutable $dateGeneration;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['remise:read'])]
    private \DateTimeImmutable $dateExecutionPrevue;

    #[ORM\Column(length: 10, enumType: StatutRemiseSepa::class, options: ['default' => 'brouillon'])]
    #[Groups(['remise:read'])]
    private StatutRemiseSepa $statut = StatutRemiseSepa::Brouillon;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['remise:read'])]
    private int $nbEcheances = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['remise:read'])]
    private int $montantTotalCentimes = 0;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateGeneration = new \DateTimeImmutable();
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

    public function getReferenceRemise(): ?string
    {
        return $this->referenceRemise;
    }

    public function setReferenceRemise(?string $referenceRemise): self
    {
        $this->referenceRemise = $referenceRemise;

        return $this;
    }

    public function getDateGeneration(): \DateTimeImmutable
    {
        return $this->dateGeneration;
    }

    public function setDateGeneration(\DateTimeImmutable $dateGeneration): self
    {
        $this->dateGeneration = $dateGeneration;

        return $this;
    }

    public function getDateExecutionPrevue(): \DateTimeImmutable
    {
        return $this->dateExecutionPrevue;
    }

    public function setDateExecutionPrevue(\DateTimeImmutable $dateExecutionPrevue): self
    {
        $this->dateExecutionPrevue = $dateExecutionPrevue;

        return $this;
    }

    public function getStatut(): StatutRemiseSepa
    {
        return $this->statut;
    }

    public function setStatut(StatutRemiseSepa $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getNbEcheances(): int
    {
        return $this->nbEcheances;
    }

    public function setNbEcheances(int $nbEcheances): self
    {
        $this->nbEcheances = $nbEcheances;

        return $this;
    }

    public function getMontantTotalCentimes(): int
    {
        return $this->montantTotalCentimes;
    }

    public function setMontantTotalCentimes(int $montantTotalCentimes): self
    {
        $this->montantTotalCentimes = $montantTotalCentimes;

        return $this;
    }
}
