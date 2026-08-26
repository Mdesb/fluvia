<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Stock\State\EstablishmentStampProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fournisseur boutique (US-STOCK-02, RG-STOCK-03) : coordonnées, conditions, désactivable mais jamais
 * supprimable si référencé par un `CatalogueFournisseur`/`CommandeAchat`. ⚠ HYPOTHÈSE — rattachement
 * établissement (Risque n°8 du plan, la spec omet ce champ), cohérent avec le reste du module.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stk_fournisseur')]
#[ApiResource(
    shortName: 'StockFournisseur',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stock.lire')"),
        new Get(security: "is_granted('PERM', 'stock.lire')"),
        new Post(
            security: "is_granted('PERM', 'stock.gerer_fournisseur') or is_granted('PERM', 'stock.gerer')",
            processor: EstablishmentStampProcessor::class,
        ),
        new Patch(security: "is_granted('PERM', 'stock.gerer_fournisseur') or is_granted('PERM', 'stock.gerer')"),
    ],
    normalizationContext: ['groups' => ['stock_fournisseur:read']],
    denormalizationContext: ['groups' => ['stock_fournisseur:write']],
)]
class Fournisseur
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['stock_fournisseur:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    // D41 — hors groupe d'ecriture : l'etablissement vient de la session serveur, pose par
    // `EstablishmentStampProcessor`, jamais du corps de la requete. Plus d'`Assert\NotNull` non plus :
    // la validation s'execute AVANT l'ecriture, donc avant l'estampillage, et echouerait en 422 sur
    // une valeur que le serveur allait poser lui-meme. L'invariant tient par l'estampilleur, qui
    // refuse plutot que de deviner, par la colonne NOT NULL, et par le garde global D41.
    #[Groups(['stock_fournisseur:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Groups(['stock_fournisseur:read', 'stock_fournisseur:write'])]
    private string $raisonSociale = '';

    #[ORM\Column(length: 14, nullable: true)]
    #[Groups(['stock_fournisseur:read', 'stock_fournisseur:write'])]
    private ?string $siret = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['stock_fournisseur:read', 'stock_fournisseur:write'])]
    private ?string $contact = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['stock_fournisseur:read', 'stock_fournisseur:write'])]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['stock_fournisseur:read', 'stock_fournisseur:write'])]
    private ?string $telephone = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['stock_fournisseur:read', 'stock_fournisseur:write'])]
    private ?string $adresse = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['stock_fournisseur:read', 'stock_fournisseur:write'])]
    private ?string $conditionsPaiement = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['stock_fournisseur:read', 'stock_fournisseur:write'])]
    private bool $actif = true;

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

    public function getRaisonSociale(): string
    {
        return $this->raisonSociale;
    }

    public function setRaisonSociale(string $raisonSociale): self
    {
        $this->raisonSociale = $raisonSociale;

        return $this;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): self
    {
        $this->siret = $siret;

        return $this;
    }

    public function getContact(): ?string
    {
        return $this->contact;
    }

    public function setContact(?string $contact): self
    {
        $this->contact = $contact;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): self
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): self
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getConditionsPaiement(): ?string
    {
        return $this->conditionsPaiement;
    }

    public function setConditionsPaiement(?string $conditionsPaiement): self
    {
        $this->conditionsPaiement = $conditionsPaiement;

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
