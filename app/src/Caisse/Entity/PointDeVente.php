<?php

declare(strict_types=1);

namespace App\Caisse\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Point de vente (guichet) : conditionne l'imprimante, les TPE, les favoris et les moyens de
 * paiement autorisés (RG-M2-02). Rattaché à un Établissement (RG-SOCLE-01). Le seuil d'impression
 * par défaut est 0 € = impression systématique (décision actée seuil ; ⚠ défaut à confirmer).
 */
#[ORM\Entity]
#[ORM\Table(name: 'caisse_point_de_vente')]
// D44-bis — le point de vente « Vente directe » est résolu **par son libellé**, faute de code sur
// l'entité. Sans cette contrainte, un second point de vente du même nom (l'API le permet : `caisse.gerer`
// suffit) scinderait silencieusement une chaîne NF525 en deux. Chacune resterait vérifiable, et
// l'ensemble ne le serait plus — la sorte de dégât qu'on ne constate qu'au contrôle.
#[ORM\UniqueConstraint(name: 'uniq_pdv_etablissement_libelle', columns: ['etablissement_id', 'libelle'])]
#[ApiResource(
    shortName: 'PointDeVente',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'caisse.lire') or is_granted('PERM', 'vente.lire')"),
        new Get(security: "is_granted('PERM', 'caisse.lire') or is_granted('PERM', 'vente.lire')"),
        new Post(security: "is_granted('PERM', 'caisse.gerer')"),
        new Patch(security: "is_granted('PERM', 'caisse.gerer')"),
    ],
    normalizationContext: ['groups' => ['pdv:read']],
    denormalizationContext: ['groups' => ['pdv:write']],
)]
class PointDeVente
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['pdv:read', 'session:read', 'caisse:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['pdv:read', 'pdv:write', 'session:read', 'caisse:read'])]
    private string $libelle = '';

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['pdv:read', 'pdv:write'])]
    private ?Etablissement $etablissement = null;

    /** @var array<string, mixed>|null Config imprimante ticket (paramétrage Admin). */
    #[ORM\Column(nullable: true)]
    #[Groups(['pdv:read', 'pdv:write'])]
    private ?array $imprimante = null;

    /** @var list<array<string, mixed>>|null Terminaux TPE {marque∈{ingenico,nayax,pax}, ref} (US-L2-07). */
    #[ORM\Column(nullable: true)]
    #[Groups(['pdv:read', 'pdv:write'])]
    private ?array $tpe = null;

    /** @var list<string>|null UUID de Produit (M1) épinglés à l'écran de caisse (US-L2-02). */
    #[ORM\Column(nullable: true)]
    #[Groups(['pdv:read', 'pdv:write'])]
    private ?array $favoris = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Assert\PositiveOrZero]
    #[Groups(['pdv:read', 'pdv:write'])]
    private string $seuilImpression = '0.00';

    /**
     * Seuil de montant d'un retrait d'espèces déclenchant une alerte régisseur (cahier M2-§8).
     * Null = pas d'alerte automatique.
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['pdv:read', 'pdv:write'])]
    private ?string $seuilAlerteRetrait = null;

    /** @var list<string> Codes de moyens de paiement autorisés ⊂ référentiel M6 (RG-M2-02). */
    #[ORM\Column]
    #[Groups(['pdv:read', 'pdv:write'])]
    private array $moyensAutorises = [];

    /**
     * Tolérance d'écart de caisse (RG-CAISSEZ-08) : en dessous de ce seuil (valeur absolue), aucune
     * `AlerteEcartCaisse` n'est créée à la clôture. Défaut 0,00 € : tout écart non nul déclenche une
     * alerte tant qu'aucune tolérance n'est paramétrée explicitement. Même patron que `seuilImpression`
     * (colonne NOT NULL DEFAULT '0.00', pas nullable contrairement à `seuilAlerteRetrait`).
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Assert\PositiveOrZero]
    #[Groups(['pdv:read', 'pdv:write'])]
    private string $toleranceEcartCaisse = '0.00';

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

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getImprimante(): ?array
    {
        return $this->imprimante;
    }

    /** @param array<string, mixed>|null $imprimante */
    public function setImprimante(?array $imprimante): self
    {
        $this->imprimante = $imprimante;

        return $this;
    }

    /** @return list<array<string, mixed>>|null */
    public function getTpe(): ?array
    {
        return $this->tpe;
    }

    /** @param list<array<string, mixed>>|null $tpe */
    public function setTpe(?array $tpe): self
    {
        $this->tpe = $tpe;

        return $this;
    }

    /** @return list<string>|null */
    public function getFavoris(): ?array
    {
        return $this->favoris;
    }

    /** @param list<string>|null $favoris */
    public function setFavoris(?array $favoris): self
    {
        $this->favoris = $favoris === null ? null : array_values($favoris);

        return $this;
    }

    public function getSeuilImpression(): string
    {
        return $this->seuilImpression;
    }

    public function setSeuilImpression(string $seuilImpression): self
    {
        $this->seuilImpression = $seuilImpression;

        return $this;
    }

    public function getSeuilAlerteRetrait(): ?string
    {
        return $this->seuilAlerteRetrait;
    }

    public function setSeuilAlerteRetrait(?string $seuilAlerteRetrait): self
    {
        $this->seuilAlerteRetrait = $seuilAlerteRetrait;

        return $this;
    }

    /** @return list<string> */
    public function getMoyensAutorises(): array
    {
        return $this->moyensAutorises;
    }

    /** @param list<string> $moyensAutorises */
    public function setMoyensAutorises(array $moyensAutorises): self
    {
        $this->moyensAutorises = array_values($moyensAutorises);

        return $this;
    }

    public function autoriseMoyen(string $code): bool
    {
        return \in_array($code, $this->moyensAutorises, true);
    }

    public function getToleranceEcartCaisse(): string
    {
        return $this->toleranceEcartCaisse;
    }

    public function setToleranceEcartCaisse(string $toleranceEcartCaisse): self
    {
        $this->toleranceEcartCaisse = $toleranceEcartCaisse;

        return $this;
    }
}
