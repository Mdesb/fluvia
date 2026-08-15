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
 * Mapping obligatoire catégorie comptable (M1, axe comptable RG-M1-05) → compte de produit + taux
 * TVA (RG-M6-01/TVA-06). Un mapping incomplet bloque la génération d'écriture pour les ventes de la
 * catégorie (CA-2, `MappingComptableGuard`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_mapping_comptable')]
#[ORM\UniqueConstraint(name: 'uniq_mapping_profil_categorie', columns: ['profil_exploitant_id', 'categorie'])]
#[ApiResource(
    shortName: 'MappingComptable',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(security: "is_granted('PERM', 'compta.gerer')"),
        new Patch(security: "is_granted('PERM', 'compta.gerer')"),
    ],
    normalizationContext: ['groups' => ['mapping:read']],
    denormalizationContext: ['groups' => ['mapping:write']],
)]
class MappingComptable
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['mapping:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['mapping:read', 'mapping:write'])]
    private ?ProfilExploitant $profilExploitant = null;

    /** Réf. logique Categorie(axe=comptable) — M1, pas de FK dure (M1 reste autoritaire). */
    #[ORM\Column(type: UuidType::NAME)]
    #[Assert\NotNull]
    #[Groups(['mapping:read', 'mapping:write'])]
    private ?Uuid $categorie = null;

    #[ORM\ManyToOne(targetEntity: CompteComptable::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['mapping:read', 'mapping:write'])]
    private ?CompteComptable $compteProduit = null;

    #[ORM\ManyToOne(targetEntity: TauxTva::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['mapping:read', 'mapping:write'])]
    private ?TauxTva $tauxTva = null;

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

    public function getCategorie(): ?Uuid
    {
        return $this->categorie;
    }

    public function setCategorie(?Uuid $categorie): self
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getCompteProduit(): ?CompteComptable
    {
        return $this->compteProduit;
    }

    public function setCompteProduit(?CompteComptable $compteProduit): self
    {
        $this->compteProduit = $compteProduit;

        return $this;
    }

    public function getTauxTva(): ?TauxTva
    {
        return $this->tauxTva;
    }

    public function setTauxTva(?TauxTva $tauxTva): self
    {
        $this->tauxTva = $tauxTva;

        return $this;
    }

    public function estValide(): bool
    {
        return $this->compteProduit !== null && $this->compteProduit->isActif()
            && $this->tauxTva !== null && $this->tauxTva->isActif();
    }
}
