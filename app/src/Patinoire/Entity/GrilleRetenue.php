<?php

declare(strict_types=1);

namespace App\Patinoire\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Patinoire\Enum\ModeRetenue;
use App\Patinoire\Enum\MotifRetenue;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Grille de retenue paramétrable par établissement (décision actée « patins non rendus/cassés »,
 * US-PATIN-04, §4.4). Porte, par motif, un mode (forfait ou valeur de remplacement — cahier §7, les
 * deux modes coexistent) et un montant/taux. `parcPatins` optionnel permet une granularité par
 * pointure (priorité sur la règle établissement générale, `ResolveurGrilleRetenueHandler`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'patin_grille_retenue')]
#[ApiResource(
    shortName: 'PatinoireGrilleRetenue',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'patinoire.lire')"),
        new Get(security: "is_granted('PERM', 'patinoire.lire')"),
        new Post(security: "is_granted('PERM', 'patinoire.configurer')"),
        new Patch(security: "is_granted('PERM', 'patinoire.configurer')"),
    ],
    normalizationContext: ['groups' => ['grille_retenue:read']],
    denormalizationContext: ['groups' => ['grille_retenue:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['motif' => 'exact', 'parcPatins' => 'exact', 'actif' => 'exact', 'etablissement' => 'exact'])]
class GrilleRetenue
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['grille_retenue:read', 'retenue:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 22, enumType: MotifRetenue::class)]
    #[Assert\NotNull]
    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    private ?MotifRetenue $motif = null;

    #[ORM\Column(length: 20, enumType: ModeRetenue::class)]
    #[Assert\NotNull]
    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    private ?ModeRetenue $mode = null;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    private string $montantOuTaux = '0.00';

    #[ORM\ManyToOne(targetEntity: ParcPatins::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    private ?ParcPatins $parcPatins = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    private bool $actif = true;

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

    public function getMotif(): ?MotifRetenue
    {
        return $this->motif;
    }

    public function setMotif(?MotifRetenue $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getMode(): ?ModeRetenue
    {
        return $this->mode;
    }

    public function setMode(?ModeRetenue $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function getMontantOuTaux(): string
    {
        return $this->montantOuTaux;
    }

    public function setMontantOuTaux(string $montantOuTaux): self
    {
        $this->montantOuTaux = $montantOuTaux;

        return $this;
    }

    public function getParcPatins(): ?ParcPatins
    {
        return $this->parcPatins;
    }

    public function setParcPatins(?ParcPatins $parcPatins): self
    {
        $this->parcPatins = $parcPatins;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }
}
