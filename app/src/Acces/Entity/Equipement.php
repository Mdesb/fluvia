<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Acces\Enum\SensEquipement;
use App\Acces\Enum\TypeEquipement;
use App\Acces\Validator as AccesAssert;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Équipement de franchissement (tourniquet/tripode iDTRONIC ou lecteur QR/RFID), rattaché à un
 * Contrôleur (US-L3-01). Le sens pilote l'impact FMI (RG-ACC-04) ; l'anti-passback et les marges
 * sont surchargeables localement (§4.1/4.2). `TopologieCoherente` refuse l'enregistrement d'une
 * topologie incohérente (CA-1).
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_equipement')]
#[AccesAssert\TopologieCoherente]
#[ApiResource(
    shortName: 'Equipement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'acces.lire')"),
        new Get(security: "is_granted('PERM', 'acces.lire')"),
        new Post(security: "is_granted('PERM', 'acces.gerer')"),
        new Patch(security: "is_granted('PERM', 'acces.gerer')"),
    ],
    normalizationContext: ['groups' => ['equipement:read']],
    denormalizationContext: ['groups' => ['equipement:write']],
)]
class Equipement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['equipement:read', 'passage:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['equipement:read', 'equipement:write', 'passage:read'])]
    private string $libelle = '';

    #[ORM\ManyToOne(targetEntity: Controleur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Un équipement orphelin (sans contrôleur) est refusé (CA-1).')]
    #[Groups(['equipement:read', 'equipement:write', 'passage:read'])]
    private ?Controleur $controleur = null;

    #[ORM\Column(length: 16, enumType: TypeEquipement::class)]
    #[Assert\NotNull]
    #[Groups(['equipement:read', 'equipement:write'])]
    private ?TypeEquipement $type = null;

    #[ORM\Column(length: 16, enumType: SensEquipement::class, nullable: true)]
    #[Assert\NotNull(message: 'Le sens est requis : un équipement sans sens est refusé (CA-1).')]
    #[Groups(['equipement:read', 'equipement:write'])]
    private ?SensEquipement $sens = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['equipement:read', 'equipement:write'])]
    private ?bool $antiPassbackActif = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    #[Groups(['equipement:read', 'equipement:write'])]
    private ?int $antiPassbackDelai = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['equipement:read', 'equipement:write'])]
    private int $margeAvance = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['equipement:read', 'equipement:write'])]
    private int $margeRetard = 0;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['equipement:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getControleur(): ?Controleur
    {
        return $this->controleur;
    }

    public function setControleur(?Controleur $controleur): self
    {
        $this->controleur = $controleur;
        if ($controleur !== null) {
            $this->etablissement = $controleur->getEtablissement();
        }

        return $this;
    }

    public function getType(): ?TypeEquipement
    {
        return $this->type;
    }

    public function setType(?TypeEquipement $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getSens(): ?SensEquipement
    {
        return $this->sens;
    }

    public function setSens(?SensEquipement $sens): self
    {
        $this->sens = $sens;

        return $this;
    }

    public function getAntiPassbackActif(): ?bool
    {
        return $this->antiPassbackActif;
    }

    public function setAntiPassbackActif(?bool $antiPassbackActif): self
    {
        $this->antiPassbackActif = $antiPassbackActif;

        return $this;
    }

    public function getAntiPassbackDelai(): ?int
    {
        return $this->antiPassbackDelai;
    }

    public function setAntiPassbackDelai(?int $antiPassbackDelai): self
    {
        $this->antiPassbackDelai = $antiPassbackDelai;

        return $this;
    }

    public function getMargeAvance(): int
    {
        return $this->margeAvance;
    }

    public function setMargeAvance(int $margeAvance): self
    {
        $this->margeAvance = $margeAvance;

        return $this;
    }

    public function getMargeRetard(): int
    {
        return $this->margeRetard;
    }

    public function setMargeRetard(int $margeRetard): self
    {
        $this->margeRetard = $margeRetard;

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
}
