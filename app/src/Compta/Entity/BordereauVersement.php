<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Bordereau de versement (US-L4-02, CA-5) : daté, montant, justificatifs. Décrémente le solde
 * d'encaisse de la régie et déclenche la génération de l'écriture correspondante (RG-M6-10).
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_bordereau_versement')]
#[ApiResource(
    shortName: 'BordereauVersement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
    ],
    normalizationContext: ['groups' => ['bordereau:read']],
)]
class BordereauVersement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['bordereau:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: RegieRecettes::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['bordereau:read'])]
    private ?RegieRecettes $regie = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['bordereau:read'])]
    private \DateTimeImmutable $dateVersement;

    #[ORM\Column]
    #[Groups(['bordereau:read'])]
    private int $montantCentimes = 0;

    /** @var list<string>|null références de fichiers justificatifs. */
    #[ORM\Column(nullable: true)]
    #[Groups(['bordereau:read'])]
    private ?array $justificatifs = null;

    #[ORM\ManyToOne(targetEntity: EcritureComptable::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['bordereau:read'])]
    private ?EcritureComptable $ecritureGeneree = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateVersement = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRegie(): ?RegieRecettes
    {
        return $this->regie;
    }

    public function setRegie(?RegieRecettes $regie): self
    {
        $this->regie = $regie;

        return $this;
    }

    public function getDateVersement(): \DateTimeImmutable
    {
        return $this->dateVersement;
    }

    public function setDateVersement(\DateTimeImmutable $dateVersement): self
    {
        $this->dateVersement = $dateVersement;

        return $this;
    }

    public function getMontantCentimes(): int
    {
        return $this->montantCentimes;
    }

    public function setMontantCentimes(int $montantCentimes): self
    {
        $this->montantCentimes = $montantCentimes;

        return $this;
    }

    /** @return list<string>|null */
    public function getJustificatifs(): ?array
    {
        return $this->justificatifs;
    }

    /** @param list<string>|null $justificatifs */
    public function setJustificatifs(?array $justificatifs): self
    {
        $this->justificatifs = $justificatifs;

        return $this;
    }

    public function getEcritureGeneree(): ?EcritureComptable
    {
        return $this->ecritureGeneree;
    }

    public function setEcritureGeneree(?EcritureComptable $ecritureGeneree): self
    {
        $this->ecritureGeneree = $ecritureGeneree;

        return $this;
    }
}
