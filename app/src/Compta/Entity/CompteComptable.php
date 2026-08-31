<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Compta\Enum\SensCompte;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compte du plan de comptes actif (M57/M4/PCG selon le profil) — US-L4-01. Numéro unique par profil
 * exploitant. Non supprimable si référencé (garde applicative laissée à l'admin, hors périmètre
 * technique strict de ce lot ; désactivable via `actif`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_compte_comptable')]
#[ORM\UniqueConstraint(name: 'uniq_compte_profil_numero', columns: ['profil_exploitant_id', 'numero'])]
#[ApiResource(
    shortName: 'CompteComptable',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(security: "is_granted('PERM', 'compta.gerer')"),
        new Patch(security: "is_granted('PERM', 'compta.gerer')"),
    ],
    normalizationContext: ['groups' => ['compte:read']],
    denormalizationContext: ['groups' => ['compte:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['profilExploitant' => 'exact', 'numero' => 'partial'])]
class CompteComptable
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['compte:read', 'mapping:read', 'ligne:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['compte:read', 'compte:write'])]
    private ?ProfilExploitant $profilExploitant = null;

    #[ORM\Column(length: 16)]
    #[Assert\NotBlank]
    #[Groups(['compte:read', 'compte:write', 'mapping:read', 'ligne:read'])]
    private string $numero = '';

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Groups(['compte:read', 'compte:write', 'mapping:read', 'ligne:read'])]
    private string $libelle = '';

    #[ORM\Column(length: 8, enumType: SensCompte::class)]
    #[Assert\NotNull]
    #[Groups(['compte:read', 'compte:write'])]
    private SensCompte $sens = SensCompte::Debit;

    // ⚠ Exposé dans `mapping:read` pour que l'écran des correspondances puisse dire POURQUOI
    // une correspondance est inopérante : un verdict sans cause envoie chercher.
    #[ORM\Column(options: ['default' => true])]
    #[Groups(['compte:read', 'compte:write', 'mapping:read'])]
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

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): self
    {
        $this->numero = $numero;

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

    public function getSens(): SensCompte
    {
        return $this->sens;
    }

    public function setSens(SensCompte $sens): self
    {
        $this->sens = $sens;

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
