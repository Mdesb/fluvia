<?php

declare(strict_types=1);

namespace App\OptionProduit\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\OptionProduit\Enum\ModeSelectionOption;
use App\Offre\State\TenantReferenceProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Groupe d'options (US-OPT-01, RG-OPT-01) : référentiel réutilisable entre produits (comme
 * `TypeTarif`/`Saison` en M1), à choix unique ou multiple. Non supprimable (pas d'opération
 * `Delete`) : seule la désactivation (`actif = false`) est possible, cohérent avec le patron
 * « non-suppression des référentiels utilisés » de M1 (RG-OPT-08).
 */
#[ORM\Entity]
#[ORM\Table(name: 'opt_groupe')]
#[ApiResource(
    shortName: 'GroupeOption',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'offre.lire')"),
        new Get(security: "is_granted('PERM', 'offre.lire')"),
        // ⚠ L'ETABLISSEMENT EST POSE ICI, PAS DEMANDE AU CLIENT. Meme patron que `Saison` :
        // le champ est hors du groupe d'ecriture, et `TenantReferenceProcessor` le renseigne
        // depuis l'etablissement actif. Le demander au client reintroduirait exactement la
        // fuite qu'on ferme — il suffirait d'en designer un autre.
        new Post(security: "is_granted('PERM', 'offre.modifier')", processor: TenantReferenceProcessor::class),
        new Patch(security: "is_granted('PERM', 'offre.modifier')"),
    ],
    normalizationContext: ['groups' => ['groupe_option:read']],
    denormalizationContext: ['groups' => ['groupe_option:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['libelle' => 'partial', 'actif' => 'exact'])]
class GroupeOption
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['groupe_option:read', 'valeur_option:read', 'option_produit:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['groupe_option:read', 'groupe_option:write', 'valeur_option:read', 'option_produit:read'])]
    private string $libelle = '';

    #[ORM\Column(length: 8, enumType: ModeSelectionOption::class)]
    #[Assert\NotNull]
    #[Groups(['groupe_option:read', 'groupe_option:write', 'option_produit:read'])]
    private ?ModeSelectionOption $modeSelection = null;

    /** @var Collection<int, ValeurOption> */
    /**
     * ⚠ LE PROPRIETAIRE, AJOUTE LE 31/08 — CETTE ENTITE N'EN AVAIT AUCUN.
     *
     * Elle ne portait AUCUNE relation sortante : elle etait atteinte depuis le pivot
     * `OptionProduit`, jamais l'inverse. Aucune extension Doctrine ne peut cloisonner ce qui ne
     * pointe vers rien — d'ou une colonne plutot qu'une entree dans une carte.
     *
     * Mesure du 31/08 : un groupe cree sur un etablissement etait visible depuis l'autre, et
     * `ValeurOption` expose l'impact tarifaire de chaque option. Voir `OptionsPartageesTest`.
     *
     * Nullable et hors du groupe d'ecriture, comme `Saison` : c'est `TenantReferenceProcessor` qui
     * le pose au POST. Une ligne sans etablissement n'est visible de personne — la migration
     * `Version20260831220000` les rattache toutes depuis leurs produits.
     */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['groupe_option:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\OneToMany(targetEntity: ValeurOption::class, mappedBy: 'groupeOption')]
    #[Groups(['groupe_option:read'])]
    private Collection $valeurs;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['groupe_option:read', 'groupe_option:write', 'option_produit:read'])]
    private bool $actif = true;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['groupe_option:read'])]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['groupe_option:read'])]
    private \DateTimeImmutable $modifieLe;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->valeurs = new ArrayCollection();
        $this->creeLe = new \DateTimeImmutable();
        $this->modifieLe = new \DateTimeImmutable();
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

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getModeSelection(): ?ModeSelectionOption
    {
        return $this->modeSelection;
    }

    public function setModeSelection(?ModeSelectionOption $modeSelection): self
    {
        $this->modeSelection = $modeSelection;

        return $this;
    }

    /** @return Collection<int, ValeurOption> */
    public function getValeurs(): Collection
    {
        return $this->valeurs;
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
