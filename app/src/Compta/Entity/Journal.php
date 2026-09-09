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
 * Journal (RG-COMPTA-04) : ventes / encaissements / régie / PCA-OD / extourne, par profil exploitant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_journal')]
#[ORM\UniqueConstraint(name: 'uniq_journal_profil_code', columns: ['profil_exploitant_id', 'code'])]
#[ApiResource(
    shortName: 'Journal',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(security: "is_granted('PERM', 'compta.gerer')"),
        new Patch(security: "is_granted('PERM', 'compta.gerer')"),
    ],
    normalizationContext: ['groups' => ['journal:read']],
    denormalizationContext: ['groups' => ['journal:write']],
)]
class Journal
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['journal:read', 'ecriture:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['journal:read', 'journal:write'])]
    private ?ProfilExploitant $profilExploitant = null;

    #[ORM\Column(length: 8)]
    #[Assert\NotBlank]
    #[Groups(['journal:read', 'journal:write', 'ecriture:read'])]
    private string $code = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['journal:read', 'journal:write', 'ecriture:read'])]
    private string $libelle = '';

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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

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
}
