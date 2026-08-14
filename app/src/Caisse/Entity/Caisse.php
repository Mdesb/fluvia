<?php

declare(strict_types=1);

namespace App\Caisse\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Caisse\Enum\EtatCaisse;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Caisse physique rattachée à un point de vente (cahier M2-01). États 🟢 ouverte / 🟡 en_fermeture /
 * 🔒 sécurisée ; une caisse sécurisée exige le code régisseur pour être rouverte (CA-2).
 */
#[ORM\Entity]
#[ORM\Table(name: 'caisse_caisse')]
#[ApiResource(
    shortName: 'Caisse',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'caisse.lire') or is_granted('PERM', 'vente.lire')"),
        new Get(security: "is_granted('PERM', 'caisse.lire') or is_granted('PERM', 'vente.lire')"),
        new Post(security: "is_granted('PERM', 'caisse.gerer')"),
        new Patch(security: "is_granted('PERM', 'caisse.gerer')"),
    ],
    normalizationContext: ['groups' => ['caisse:read']],
    denormalizationContext: ['groups' => ['caisse:write']],
)]
class Caisse
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['caisse:read', 'session:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['caisse:read', 'caisse:write', 'session:read'])]
    private string $libelle = '';

    #[ORM\ManyToOne(targetEntity: PointDeVente::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['caisse:read', 'caisse:write', 'session:read'])]
    private ?PointDeVente $pointDeVente = null;

    #[ORM\Column(length: 16, enumType: EtatCaisse::class, options: ['default' => 'securisee'])]
    #[Groups(['caisse:read', 'session:read'])]
    private EtatCaisse $etat = EtatCaisse::Securisee;

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

    public function getPointDeVente(): ?PointDeVente
    {
        return $this->pointDeVente;
    }

    public function setPointDeVente(?PointDeVente $pointDeVente): self
    {
        $this->pointDeVente = $pointDeVente;

        return $this;
    }

    public function getEtat(): EtatCaisse
    {
        return $this->etat;
    }

    public function setEtat(EtatCaisse $etat): self
    {
        $this->etat = $etat;

        return $this;
    }
}
