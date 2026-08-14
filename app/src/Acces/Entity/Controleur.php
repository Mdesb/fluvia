<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Acces\Enum\EtatControleur;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Contrôleur rattaché à un concentrateur ITBOX (référence logique, pas de FK dure) et à un
 * EspaceAcces (US-L3-01). Porte le cycle réseau (état, heartbeat) support de la bascule
 * online/offline automatique (§4.6, RG-ACC-05) et la version de la liste de révocation embarquée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_controleur')]
#[ApiResource(
    shortName: 'Controleur',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'acces.lire')"),
        new Get(security: "is_granted('PERM', 'acces.lire')"),
        new Post(security: "is_granted('PERM', 'acces.gerer')"),
        new Patch(security: "is_granted('PERM', 'acces.gerer')"),
    ],
    normalizationContext: ['groups' => ['controleur:read']],
    denormalizationContext: ['groups' => ['controleur:write']],
)]
class Controleur
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['controleur:read', 'equipement:read', 'passage:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['controleur:read', 'controleur:write', 'equipement:read', 'passage:read'])]
    private string $libelle = '';

    #[ORM\ManyToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Un contrôleur orphelin (sans espace) est refusé (CA-1).')]
    #[Groups(['controleur:read', 'controleur:write', 'equipement:read'])]
    private ?EspaceAcces $espace = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank(message: 'Un contrôleur sans ITBOX est refusé (CA-1).')]
    #[Groups(['controleur:read', 'controleur:write'])]
    private string $itboxRef = '';

    #[ORM\Column(length: 16, enumType: EtatControleur::class, options: ['default' => 'en_ligne'])]
    #[Groups(['controleur:read', 'controleur:write'])]
    private EtatControleur $etat = EtatControleur::EnLigne;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['controleur:read'])]
    private ?\DateTimeImmutable $dernierHeartbeat = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['controleur:read'])]
    private int $versionRevocation = 0;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['controleur:read'])]
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

    public function getEspace(): ?EspaceAcces
    {
        return $this->espace;
    }

    public function setEspace(?EspaceAcces $espace): self
    {
        $this->espace = $espace;
        if ($espace !== null) {
            $this->etablissement = $espace->getEtablissement();
        }

        return $this;
    }

    public function getItboxRef(): string
    {
        return $this->itboxRef;
    }

    public function setItboxRef(string $itboxRef): self
    {
        $this->itboxRef = $itboxRef;

        return $this;
    }

    public function getEtat(): EtatControleur
    {
        return $this->etat;
    }

    public function setEtat(EtatControleur $etat): self
    {
        $this->etat = $etat;

        return $this;
    }

    public function getDernierHeartbeat(): ?\DateTimeImmutable
    {
        return $this->dernierHeartbeat;
    }

    public function setDernierHeartbeat(?\DateTimeImmutable $dernierHeartbeat): self
    {
        $this->dernierHeartbeat = $dernierHeartbeat;

        return $this;
    }

    public function getVersionRevocation(): int
    {
        return $this->versionRevocation;
    }

    public function setVersionRevocation(int $versionRevocation): self
    {
        $this->versionRevocation = $versionRevocation;

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
