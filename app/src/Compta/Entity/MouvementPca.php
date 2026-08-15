<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Compta\Enum\FaitGenerateurPca;
use App\Compta\Enum\TypeMouvementPca;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Mouvement PCA append-only : dotation (encaissement) / reprise (période ou passage Accès).
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_mouvement_pca')]
#[ApiResource(
    shortName: 'MouvementPca',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
    ],
    normalizationContext: ['groups' => ['mouvement_pca:read']],
)]
class MouvementPca
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['mouvement_pca:read', 'pca:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: EtalementPca::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mouvement_pca:read'])]
    private ?EtalementPca $etalement = null;

    #[ORM\Column(length: 10, enumType: TypeMouvementPca::class)]
    #[Groups(['mouvement_pca:read', 'pca:read'])]
    private TypeMouvementPca $type = TypeMouvementPca::Dotation;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['mouvement_pca:read', 'pca:read'])]
    private \DateTimeImmutable $dateMouvement;

    #[ORM\Column]
    #[Groups(['mouvement_pca:read', 'pca:read'])]
    private int $montantCentimes = 0;

    #[ORM\Column(length: 24, enumType: FaitGenerateurPca::class)]
    #[Groups(['mouvement_pca:read', 'pca:read'])]
    private FaitGenerateurPca $faitGenerateur = FaitGenerateurPca::Encaissement;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['mouvement_pca:read'])]
    private ?Uuid $passageOrigine = null;

    #[ORM\ManyToOne(targetEntity: EcritureComptable::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mouvement_pca:read'])]
    private ?EcritureComptable $ecritureLiee = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateMouvement = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEtalement(): ?EtalementPca
    {
        return $this->etalement;
    }

    public function setEtalement(?EtalementPca $etalement): self
    {
        $this->etalement = $etalement;

        return $this;
    }

    public function getType(): TypeMouvementPca
    {
        return $this->type;
    }

    public function setType(TypeMouvementPca $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getDateMouvement(): \DateTimeImmutable
    {
        return $this->dateMouvement;
    }

    public function setDateMouvement(\DateTimeImmutable $dateMouvement): self
    {
        $this->dateMouvement = $dateMouvement;

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

    public function getFaitGenerateur(): FaitGenerateurPca
    {
        return $this->faitGenerateur;
    }

    public function setFaitGenerateur(FaitGenerateurPca $faitGenerateur): self
    {
        $this->faitGenerateur = $faitGenerateur;

        return $this;
    }

    public function getPassageOrigine(): ?Uuid
    {
        return $this->passageOrigine;
    }

    public function setPassageOrigine(?Uuid $passageOrigine): self
    {
        $this->passageOrigine = $passageOrigine;

        return $this;
    }

    public function getEcritureLiee(): ?EcritureComptable
    {
        return $this->ecritureLiee;
    }

    public function setEcritureLiee(?EcritureComptable $ecritureLiee): self
    {
        $this->ecritureLiee = $ecritureLiee;

        return $this;
    }
}
