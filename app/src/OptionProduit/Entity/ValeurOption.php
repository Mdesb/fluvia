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
use App\OptionProduit\Enum\ImpactOptionType;
use App\Stock\Entity\ArticleStock;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Valeur d'un `GroupeOption` (US-OPT-02, RG-OPT-04) : impact tarifaire signé (montant fixe ou
 * pourcentage) appliqué par unité de ligne. Lien optionnel vers un `Stock\ArticleStock` : purement
 * informationnel dans ce lot, aucun décrément/blocage de rupture (Risque n°1 du plan). Non
 * supprimable (pas d'opération `Delete`, RG-OPT-08) : seule la désactivation est possible.
 */
#[ORM\Entity]
#[ORM\Table(name: 'opt_valeur')]
#[ApiResource(
    shortName: 'ValeurOption',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'offre.lire')"),
        new Get(security: "is_granted('PERM', 'offre.lire')"),
        new Post(security: "is_granted('PERM', 'offre.modifier')"),
        new Patch(security: "is_granted('PERM', 'offre.modifier')"),
    ],
    normalizationContext: ['groups' => ['valeur_option:read']],
    denormalizationContext: ['groups' => ['valeur_option:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['groupeOption' => 'exact', 'actif' => 'exact'])]
class ValeurOption
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['valeur_option:read', 'option_produit:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: GroupeOption::class, inversedBy: 'valeurs')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['valeur_option:read', 'valeur_option:write'])]
    private ?GroupeOption $groupeOption = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['valeur_option:read', 'valeur_option:write', 'option_produit:read'])]
    private string $libelle = '';

    #[ORM\Column(length: 11, enumType: ImpactOptionType::class)]
    #[Assert\NotNull]
    #[Groups(['valeur_option:read', 'valeur_option:write', 'option_produit:read'])]
    private ?ImpactOptionType $impactType = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotBlank]
    #[Groups(['valeur_option:read', 'valeur_option:write', 'option_produit:read'])]
    private string $impactValeur = '0.00';

    #[ORM\ManyToOne(targetEntity: ArticleStock::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['valeur_option:read', 'valeur_option:write'])]
    private ?ArticleStock $articleStock = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    #[Groups(['valeur_option:read', 'valeur_option:write', 'option_produit:read'])]
    private int $ordreAffichage = 0;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['valeur_option:read', 'valeur_option:write', 'option_produit:read'])]
    private bool $actif = true;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['valeur_option:read'])]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['valeur_option:read'])]
    private \DateTimeImmutable $modifieLe;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->creeLe = new \DateTimeImmutable();
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getImpactType(): ?ImpactOptionType
    {
        return $this->impactType;
    }

    public function setImpactType(?ImpactOptionType $impactType): self
    {
        $this->impactType = $impactType;

        return $this;
    }

    public function getImpactValeur(): string
    {
        return $this->impactValeur;
    }

    public function setImpactValeur(string $impactValeur): self
    {
        $this->impactValeur = $impactValeur;

        return $this;
    }

    public function getArticleStock(): ?ArticleStock
    {
        return $this->articleStock;
    }

    public function setArticleStock(?ArticleStock $articleStock): self
    {
        $this->articleStock = $articleStock;

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
