<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Taux de TVA (RG-TVA-06). Le taux réduit 2025 (point EXPERT #2) est créé `actif=false` par défaut ;
 * `MappingComptable` ne peut pointer que vers un taux actif.
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_taux_tva')]
#[ApiResource(
    shortName: 'TauxTva',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(security: "is_granted('PERM', 'compta.gerer')"),
        new Patch(security: "is_granted('PERM', 'compta.gerer')"),
    ],
    normalizationContext: ['groups' => ['taux:read']],
    denormalizationContext: ['groups' => ['taux:write']],
)]
class TauxTva
{
    /** Libellé conventionnel du taux « hors champ » (0 %) seedé pour les opérations non commerciales. */
    public const LIBELLE_HORS_CHAMP = 'Hors champ (opération non commerciale)';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['taux:read', 'mapping:read', 'ligne:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['taux:read', 'taux:write'])]
    private ?ProfilExploitant $profilExploitant = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['taux:read', 'taux:write', 'mapping:read', 'ligne:read'])]
    private string $taux = '0.00';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Groups(['taux:read', 'taux:write', 'ligne:read'])]
    private string $libelle = '';

    // ⚠ Exposé dans `mapping:read` pour que l'écran des correspondances puisse dire POURQUOI
    // une correspondance est inopérante : un verdict sans cause envoie chercher.
    #[ORM\Column(options: ['default' => true])]
    #[Groups(['taux:read', 'taux:write', 'mapping:read'])]
    private bool $actif = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProfilExploitant(): ?ProfilExploitant
    {
        return $this->profilExploitant;
    }

    public function setProfilExploitant(?ProfilExploitant $profilExploitant): self
    {
        $this->profilExploitant = $profilExploitant;

        return $this;
    }

    public function getEtablissement(): ?\App\Organisation\Entity\Etablissement
    {
        return $this->profilExploitant?->getEtablissementPrincipal();
    }

    public function getTaux(): string
    {
        return $this->taux;
    }

    public function setTaux(string $taux): self
    {
        $this->taux = $taux;

        return $this;
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
