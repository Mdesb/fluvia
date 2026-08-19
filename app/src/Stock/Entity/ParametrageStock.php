<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Stock\Enum\MethodeValorisation;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Paramétrage stock par établissement (RG-STOCK-08) : méthode de valorisation par défaut (héritée par
 * `ArticleStock.methodeValorisation` si null), filet de sécurité stock négatif (RG-STOCK-16, §3.3 —
 * sans point d'ancrage réel dans `DecrementStockHandler` M2, cf. Risque n°3 du plan), seuil de
 * significativité d'écart d'inventaire (RG-STOCK-12).
 */
#[ORM\Entity]
#[ORM\Table(name: 'stk_parametrage')]
#[ORM\UniqueConstraint(name: 'uniq_parametrage_etablissement', columns: ['etablissement_id'])]
#[ApiResource(
    shortName: 'StockParametrage',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stock.lire')"),
        new Get(security: "is_granted('PERM', 'stock.lire')"),
        new Post(security: "is_granted('PERM', 'stock.parametrer') or is_granted('PERM', 'stock.gerer')"),
        new Patch(security: "is_granted('PERM', 'stock.parametrer') or is_granted('PERM', 'stock.gerer')"),
    ],
    normalizationContext: ['groups' => ['parametrage:read']],
    denormalizationContext: ['groups' => ['parametrage:write']],
)]
class ParametrageStock
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['parametrage:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['parametrage:read', 'parametrage:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 4, enumType: MethodeValorisation::class)]
    #[Assert\NotNull]
    #[Groups(['parametrage:read', 'parametrage:write'])]
    private MethodeValorisation $methodeValorisationDefaut = MethodeValorisation::Fifo;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['parametrage:read', 'parametrage:write'])]
    private bool $autoriserStockNegatif = false;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    #[Groups(['parametrage:read', 'parametrage:write'])]
    private ?string $seuilEcartSignificatifPourcentage = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    #[Groups(['parametrage:read', 'parametrage:write'])]
    private ?string $seuilEcartSignificatifMontant = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    public function getMethodeValorisationDefaut(): MethodeValorisation
    {
        return $this->methodeValorisationDefaut;
    }

    public function setMethodeValorisationDefaut(MethodeValorisation $methodeValorisationDefaut): self
    {
        $this->methodeValorisationDefaut = $methodeValorisationDefaut;

        return $this;
    }

    public function isAutoriserStockNegatif(): bool
    {
        return $this->autoriserStockNegatif;
    }

    public function setAutoriserStockNegatif(bool $autoriserStockNegatif): self
    {
        $this->autoriserStockNegatif = $autoriserStockNegatif;

        return $this;
    }

    public function getSeuilEcartSignificatifPourcentage(): ?string
    {
        return $this->seuilEcartSignificatifPourcentage;
    }

    public function setSeuilEcartSignificatifPourcentage(?string $seuilEcartSignificatifPourcentage): self
    {
        $this->seuilEcartSignificatifPourcentage = $seuilEcartSignificatifPourcentage;

        return $this;
    }

    public function getSeuilEcartSignificatifMontant(): ?string
    {
        return $this->seuilEcartSignificatifMontant;
    }

    public function setSeuilEcartSignificatifMontant(?string $seuilEcartSignificatifMontant): self
    {
        $this->seuilEcartSignificatifMontant = $seuilEcartSignificatifMontant;

        return $this;
    }
}
