<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Offre\Enum\TypePromotion;
use App\Offre\State\PromotionScopeStampProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Promotion applicable à l'offre (RG-M1-04). Le bonus « 10=12 » (compostages) est porté
 * fonctionnellement par la carte multi-entrées (RG-M1-13) ; ici c'est le référentiel des règles.
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_promotion')]
#[ApiResource(
    shortName: 'Promotion',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'offre.lire')"),
        new Get(security: "is_granted('PERM', 'offre.lire')"),
        new Post(security: "is_granted('PERM', 'offre.gerer')", processor: PromotionScopeStampProcessor::class),
        new Patch(security: "is_granted('PERM', 'offre.gerer')"),
        new Delete(security: "is_granted('PERM', 'offre.gerer')"),
    ],
    normalizationContext: ['groups' => ['ref:read']],
    denormalizationContext: ['groups' => ['ref:write']],
)]
class Promotion
{
    public const CUMUL_CUMULABLE = 'cumulable';
    public const CUMUL_EXCLUSIF = 'exclusif';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ref:read'])]
    private Uuid $id;

    /**
     * Sites ou cette promotion s'applique — **cloisonnement** (RG-SOCLE-05).
     *
     * Calque sur `Produit::etablissements`, et pour une raison de fond : une promotion porte sur des
     * produits, et un produit est commercialise site par site. Lui donner une autre echelle aurait
     * cree deux notions de perimetre dans le meme module.
     *
     * **Avant ce lot, `Promotion` ne portait aucun rattachement** : la collection etait donc lisible
     * par tous les exploitants de la base, y compris d'un groupe a l'autre. Une promotion est une
     * arme commerciale, et elle etait visible des concurrents **avant meme sa date de debut**.
     *
     * @var Collection<int, Etablissement>
     */
    #[ORM\ManyToMany(targetEntity: Etablissement::class)]
    #[ORM\JoinTable(name: 'off_promotion_etablissement')]
    #[Groups(['ref:read', 'ref:write'])]
    private Collection $etablissements;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['ref:read', 'ref:write'])]
    private string $nom = '';

    #[ORM\Column(length: 24, enumType: TypePromotion::class)]
    #[Assert\NotNull]
    #[Groups(['ref:read', 'ref:write'])]
    private ?TypePromotion $type = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['ref:read', 'ref:write'])]
    private ?string $valeur = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['ref:read', 'ref:write'])]
    private ?string $conditions = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['ref:read', 'ref:write'])]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['ref:read', 'ref:write'])]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column(length: 12, options: ['default' => 'cumulable'])]
    #[Groups(['ref:read', 'ref:write'])]
    private string $cumul = self::CUMUL_CUMULABLE;

    /** @var list<string>|null */
    #[ORM\Column(nullable: true)]
    #[Groups(['ref:read', 'ref:write'])]
    private ?array $canaux = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(nullable: true)]
    #[Groups(['ref:read', 'ref:write'])]
    private ?array $eligibilite = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->etablissements = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getType(): ?TypePromotion
    {
        return $this->type;
    }

    public function setType(?TypePromotion $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getValeur(): ?string
    {
        return $this->valeur;
    }

    public function setValeur(?string $valeur): self
    {
        $this->valeur = $valeur;

        return $this;
    }

    public function getConditions(): ?string
    {
        return $this->conditions;
    }

    public function setConditions(?string $conditions): self
    {
        $this->conditions = $conditions;

        return $this;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): self
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): self
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getCumul(): string
    {
        return $this->cumul;
    }

    public function setCumul(string $cumul): self
    {
        $this->cumul = $cumul;

        return $this;
    }

    /** @return list<string>|null */
    public function getCanaux(): ?array
    {
        return $this->canaux;
    }

    /** @param list<string>|null $canaux */
    public function setCanaux(?array $canaux): self
    {
        $this->canaux = $canaux;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getEligibilite(): ?array
    {
        return $this->eligibilite;
    }

    /** @param array<string, mixed>|null $eligibilite */
    public function setEligibilite(?array $eligibilite): self
    {
        $this->eligibilite = $eligibilite;

        return $this;
    }

    /** @return Collection<int, Etablissement> */
    public function getEtablissements(): Collection
    {
        return $this->etablissements;
    }

    public function addEtablissement(Etablissement $etablissement): self
    {
        if (!$this->etablissements->contains($etablissement)) {
            $this->etablissements->add($etablissement);
        }

        return $this;
    }

    public function removeEtablissement(Etablissement $etablissement): self
    {
        $this->etablissements->removeElement($etablissement);

        return $this;
    }
}
