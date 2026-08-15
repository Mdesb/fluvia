<?php

declare(strict_types=1);

namespace App\Piscine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Piscine\Enum\EtatLigneEau;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ligne d'eau d'un bassin (US-L6-05, cahier §4). `etat` est dérivé : `reservee` tant qu'au moins une
 * affectation `CreneauPublic` active la référence (chevauchement géré par `AffectationLigneGuard`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'piscine_ligne_eau')]
#[ORM\UniqueConstraint(name: 'uniq_ligne_bassin_numero', columns: ['bassin_id', 'numero'])]
#[ApiResource(
    shortName: 'LigneEau',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'piscine.lire')"),
        new Get(security: "is_granted('PERM', 'piscine.lire')"),
        new Post(security: "is_granted('PERM', 'piscine.configurer')"),
        new Patch(security: "is_granted('PERM', 'piscine.configurer')"),
    ],
    normalizationContext: ['groups' => ['ligne:read']],
    denormalizationContext: ['groups' => ['ligne:write']],
)]
class LigneEau
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ligne:read', 'creneau_public:read'])]
    private Uuid $id;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Positive]
    #[Groups(['ligne:read', 'ligne:write', 'creneau_public:read'])]
    private int $numero = 1;

    #[ORM\ManyToOne(targetEntity: Bassin::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['ligne:read', 'ligne:write', 'creneau_public:read'])]
    private ?Bassin $bassin = null;

    #[ORM\Column(length: 10, enumType: EtatLigneEau::class, options: ['default' => 'publique'])]
    #[Groups(['ligne:read'])]
    private EtatLigneEau $etat = EtatLigneEau::Publique;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, nullable: true)]
    #[Groups(['ligne:read', 'ligne:write'])]
    private ?string $surfaceM2 = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNumero(): int
    {
        return $this->numero;
    }

    public function setNumero(int $numero): self
    {
        $this->numero = $numero;

        return $this;
    }

    public function getBassin(): ?Bassin
    {
        return $this->bassin;
    }

    public function setBassin(?Bassin $bassin): self
    {
        $this->bassin = $bassin;

        return $this;
    }

    public function getEtat(): EtatLigneEau
    {
        return $this->etat;
    }

    public function setEtat(EtatLigneEau $etat): self
    {
        $this->etat = $etat;

        return $this;
    }

    public function getSurfaceM2(): ?string
    {
        return $this->surfaceM2;
    }

    public function setSurfaceM2(?string $surfaceM2): self
    {
        $this->surfaceM2 = $surfaceM2;

        return $this;
    }
}
