<?php

declare(strict_types=1);

namespace App\Piscine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Piscine\Enum\StatutCreneauBassin;
use App\Piscine\Enum\TypeEncadrement;
use App\Piscine\State\ValiderCreneauBassinProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Créneau bassin (⚠ extension provisoire, gap M5, plan §1.3) : porte l'exigence d'encadrement
 * (RG-PISC-02) et sert de pivot aux publics (`CreneauPublic`, US-L6-06). `statut` passe à `valide`
 * uniquement via l'endpoint dédié, bloqué si aucun encadrant qualifié couvrant (CA-4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'piscine_creneau_bassin')]
#[Assert\Expression(
    "this.getDebut() === null or this.getFin() === null or this.getDebut() < this.getFin()",
    message: 'Le début du créneau doit précéder la fin.',
)]
#[ApiResource(
    shortName: 'CreneauBassin',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'piscine.lire')"),
        new Get(security: "is_granted('PERM', 'piscine.lire')"),
        new Post(security: "is_granted('PERM', 'piscine.configurer')"),
        new Patch(security: "is_granted('PERM', 'piscine.configurer')"),
        new Post(
            uriTemplate: '/piscine/creneaux-bassin/{id}/valider',
            read: true,
            input: false,
            security: "is_granted('PERM', 'piscine.configurer')",
            processor: ValiderCreneauBassinProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['creneau:read']],
    denormalizationContext: ['groups' => ['creneau:write']],
)]
class CreneauBassin
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['creneau:read', 'creneau_public:read', 'affect:read', 'jauge_public:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Bassin::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['creneau:read', 'creneau:write'])]
    private ?Bassin $bassin = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Assert\NotNull]
    #[Groups(['creneau:read', 'creneau:write'])]
    private ?\DateTimeImmutable $debut = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Assert\NotNull]
    #[Groups(['creneau:read', 'creneau:write'])]
    private ?\DateTimeImmutable $fin = null;

    #[ORM\Column(length: 8, enumType: TypeEncadrement::class, options: ['default' => 'aucune'])]
    #[Groups(['creneau:read', 'creneau:write'])]
    private TypeEncadrement $encadrantRequis = TypeEncadrement::Aucune;

    #[ORM\Column(length: 10, enumType: StatutCreneauBassin::class, options: ['default' => 'brouillon'])]
    #[Groups(['creneau:read'])]
    private StatutCreneauBassin $statut = StatutCreneauBassin::Brouillon;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getDebut(): ?\DateTimeImmutable
    {
        return $this->debut;
    }

    public function setDebut(?\DateTimeImmutable $debut): self
    {
        $this->debut = $debut;

        return $this;
    }

    public function getFin(): ?\DateTimeImmutable
    {
        return $this->fin;
    }

    public function setFin(?\DateTimeImmutable $fin): self
    {
        $this->fin = $fin;

        return $this;
    }

    public function getEncadrantRequis(): TypeEncadrement
    {
        return $this->encadrantRequis;
    }

    public function setEncadrantRequis(TypeEncadrement $encadrantRequis): self
    {
        $this->encadrantRequis = $encadrantRequis;

        return $this;
    }

    public function getStatut(): StatutCreneauBassin
    {
        return $this->statut;
    }

    public function setStatut(StatutCreneauBassin $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    /** Chevauchement temporel [debut, fin[ (utilisé par `AffectationLigneGuard`, CA-6). */
    public function chevauche(self $autre): bool
    {
        if ($this->debut === null || $this->fin === null || $autre->debut === null || $autre->fin === null) {
            return false;
        }

        return $this->debut < $autre->fin && $autre->debut < $this->fin;
    }

    /** Dénormalisation logique pour l'audit/cloisonnement (délégation, pas de colonne, plan §5). */
    public function getEtablissement(): ?Etablissement
    {
        return $this->bassin?->getEtablissement();
    }
}
