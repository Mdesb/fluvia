<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Acces\Enum\ModeSeuil;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Jauge FMI = présence simultanée (RG-ACC-04) : `valeurCourante` (entrées − sorties) est strictement
 * distincte de `cumulJour` (ne fait qu'augmenter). Un incrément/décrément se fait par UPDATE
 * conditionnel atomique (§1.5 du plan, même technique que M2 §6).
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_jauge_fmi')]
#[ORM\UniqueConstraint(name: 'uniq_jauge_espace', columns: ['espace_id'])]
#[ApiResource(
    shortName: 'JaugeFmi',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'acces.superviser')"),
        new Get(security: "is_granted('PERM', 'acces.superviser')"),
    ],
    normalizationContext: ['groups' => ['jauge:read']],
)]
class JaugeFmi
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['jauge:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['jauge:read'])]
    private ?EspaceAcces $espace = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['jauge:read'])]
    private int $valeurCourante = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['jauge:read'])]
    private int $seuil = 0;

    #[ORM\Column(length: 12, enumType: ModeSeuil::class, options: ['default' => 'blocage'])]
    #[Groups(['jauge:read'])]
    private ModeSeuil $mode = ModeSeuil::Blocage;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['jauge:read'])]
    private int $cumulJour = 0;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['jauge:read'])]
    private \DateTimeImmutable $dateReference;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateReference = new \DateTimeImmutable('today');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEspace(): ?EspaceAcces
    {
        return $this->espace;
    }

    public function setEspace(?EspaceAcces $espace): self
    {
        $this->espace = $espace;

        return $this;
    }

    public function getValeurCourante(): int
    {
        return $this->valeurCourante;
    }

    public function setValeurCourante(int $valeurCourante): self
    {
        $this->valeurCourante = $valeurCourante;

        return $this;
    }

    public function getSeuil(): int
    {
        return $this->seuil;
    }

    public function setSeuil(int $seuil): self
    {
        $this->seuil = $seuil;

        return $this;
    }

    public function getMode(): ModeSeuil
    {
        return $this->mode;
    }

    public function setMode(ModeSeuil $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function getCumulJour(): int
    {
        return $this->cumulJour;
    }

    public function setCumulJour(int $cumulJour): self
    {
        $this->cumulJour = $cumulJour;

        return $this;
    }

    public function getDateReference(): \DateTimeImmutable
    {
        return $this->dateReference;
    }

    public function setDateReference(\DateTimeImmutable $dateReference): self
    {
        $this->dateReference = $dateReference;

        return $this;
    }
}
