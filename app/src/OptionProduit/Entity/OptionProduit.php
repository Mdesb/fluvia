<?php

declare(strict_types=1);

namespace App\OptionProduit\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\OptionProduit\State\OptionsDisponiblesProvider;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Rattachement d'un `GroupeOption` à un `Produit` (US-OPT-01, RG-OPT-02, pivot). Porte les attributs
 * propres à ce rattachement (`obligatoire`, `ordreAffichage`, `etablissementsRestriction`) : un même
 * groupe référentiel peut être obligatoire sur un produit A et facultatif sur un produit B (CA-2).
 * `UniqueEntity` donne une réponse 422 propre (pas d'erreur SQL brute) si le couple (produit,
 * groupeOption) est déjà rattaché. `Delete` détache le groupe du produit : sûr, l'historique des
 * ventes ne référence jamais l'id `OptionProduit` (seulement `groupeOptionId`/`valeurOptionId` dans
 * le snapshot JSON de `LigneVente.optionsSelectionnees`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'opt_option_produit')]
#[ORM\UniqueConstraint(name: 'uniq_option_produit_groupe', columns: ['produit_id', 'groupe_option_id'])]
#[UniqueEntity(fields: ['produit', 'groupeOption'], message: 'Ce groupe est déjà rattaché à ce produit (RG-OPT-02).')]
#[ApiResource(
    shortName: 'OptionProduit',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'offre.lire')"),
        new Get(security: "is_granted('PERM', 'offre.lire')"),
        new Post(security: "is_granted('PERM', 'offre.modifier')"),
        new Patch(security: "is_granted('PERM', 'offre.modifier')"),
        new Delete(security: "is_granted('PERM', 'offre.modifier')"),
        // GET /produits/{produitId}/options-disponibles (T6, RG-OPT-07/08) : filtrage actif +
        // établissement, provider dédié (renvoie une JsonResponse, même patron que
        // App\Crm\Entity\Client 'fiche-360'). Placeholder distinct de `id` (identifiant propre à
        // OptionProduit) : même patron que App\Support\Entity\VersionArticle::historique.
        new GetCollection(
            uriTemplate: '/produits/{produitId}/options-disponibles',
            uriVariables: [
                'produitId' => new Link(fromClass: Produit::class, identifiers: ['id']),
            ],
            security: "is_granted('PERM', 'offre.lire')",
            provider: OptionsDisponiblesProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['option_produit:read']],
    denormalizationContext: ['groups' => ['option_produit:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['produit' => 'exact'])]
class OptionProduit
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['option_produit:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['option_produit:read', 'option_produit:write'])]
    private ?Produit $produit = null;

    #[ORM\ManyToOne(targetEntity: GroupeOption::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['option_produit:read', 'option_produit:write'])]
    private ?GroupeOption $groupeOption = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['option_produit:read', 'option_produit:write'])]
    private bool $obligatoire = false;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    #[Groups(['option_produit:read', 'option_produit:write'])]
    private int $ordreAffichage = 0;

    /** @var Collection<int, Etablissement> Vide = tous les sites du produit (RG-OPT-07). */
    #[ORM\ManyToMany(targetEntity: Etablissement::class)]
    #[ORM\JoinTable(name: 'opt_option_produit_etablissement')]
    #[Groups(['option_produit:read', 'option_produit:write'])]
    private Collection $etablissementsRestriction;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['option_produit:read', 'option_produit:write'])]
    private bool $actif = true;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['option_produit:read'])]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['option_produit:read'])]
    private \DateTimeImmutable $modifieLe;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->etablissementsRestriction = new ArrayCollection();
        $this->creeLe = new \DateTimeImmutable();
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): self
    {
        $this->produit = $produit;

        return $this;
    }

    public function getGroupeOption(): ?GroupeOption
    {
        return $this->groupeOption;
    }

    public function setGroupeOption(?GroupeOption $groupeOption): self
    {
        $this->groupeOption = $groupeOption;

        return $this;
    }

    public function isObligatoire(): bool
    {
        return $this->obligatoire;
    }

    public function setObligatoire(bool $obligatoire): self
    {
        $this->obligatoire = $obligatoire;

        return $this;
    }

    public function getOrdreAffichage(): int
    {
        return $this->ordreAffichage;
    }

    public function setOrdreAffichage(int $ordreAffichage): self
    {
        $this->ordreAffichage = $ordreAffichage;

        return $this;
    }

    /** @return Collection<int, Etablissement> */
    public function getEtablissementsRestriction(): Collection
    {
        return $this->etablissementsRestriction;
    }

    public function addEtablissementRestriction(Etablissement $etablissement): self
    {
        if (!$this->etablissementsRestriction->contains($etablissement)) {
            $this->etablissementsRestriction->add($etablissement);
        }

        return $this;
    }

    public function removeEtablissementRestriction(Etablissement $etablissement): self
    {
        $this->etablissementsRestriction->removeElement($etablissement);

        return $this;
    }

    /**
     * Alias adder/remover attendu par le devineur du serializer Symfony : le nom composé de la
     * propriété (`etablissementsRestriction`) ne se laisse pas singulariser proprement (il cherche
     * `add{Property}`/`remove{Property}` sur le nom **non modifié**, pas `addEtablissementRestriction`
     * au singulier) — sans cet alias, la désérialisation API (POST/PATCH) ignorait silencieusement
     * le champ (aucune erreur levée).
     */
    public function addEtablissementsRestriction(Etablissement $etablissement): self
    {
        return $this->addEtablissementRestriction($etablissement);
    }

    /** @see self::addEtablissementsRestriction() */
    public function removeEtablissementsRestriction(Etablissement $etablissement): self
    {
        $this->etablissementsRestriction->removeElement($etablissement);

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

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function toucherModifieLe(): self
    {
        $this->modifieLe = new \DateTimeImmutable();

        return $this;
    }
}
