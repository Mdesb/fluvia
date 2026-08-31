<?php

declare(strict_types=1);

namespace App\Reservation\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Validator\TypeRessourceValide;
use App\Reservation\State\EstablishmentStampProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ressource réservable générique (terrain, glace, ligne d'eau, salle, court, table, guide…),
 * RG-M5-03/05/08. Le `codeType` est une valeur de configuration libre (référentiel extensible,
 * décision structurante n°1 du plan), pas un `if` codé en dur (constitution §4.4). Une ressource
 * partageable (`partageable=true`) porte des sous-ressources (`ressourceMere`) et maintient un
 * compteur de jauge globale (`occupationCourante`, RG-M5-08, même patron que `Piscine\Bassin`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_ressource')]
#[ApiResource(
    shortName: 'ReservationRessource',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reservation.lire')"),
        new Get(security: "is_granted('PERM', 'reservation.lire')"),
        new Post(security: "is_granted('PERM', 'reservation.gerer_ressource')", processor: EstablishmentStampProcessor::class),
        new Patch(security: "is_granted('PERM', 'reservation.gerer_ressource')"),
    ],
    normalizationContext: ['groups' => ['ressource:read']],
    denormalizationContext: ['groups' => ['ressource:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['codeType' => 'exact', 'etablissement' => 'exact', 'espace' => 'exact', 'actif' => 'exact'])]
class Ressource
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ressource:read', 'creneau:read', 'reservation:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    // D41 — plus d'`Assert\NotNull` ici : la contrainte protegeait d'un client qui OMETTAIT
    // le champ, or il ne peut plus l'envoyer du tout. La validation s'execute avant l'ecriture,
    // donc avant l'estampillage — elle echouait sur une valeur que le serveur allait poser
    // lui-meme (verifie : 422 avant d'atteindre le processor). L'invariant est desormais tenu
    // par trois choses plus solides qu'une annotation : l'estampilleur, qui refuse plutot que
    // de deviner ; la colonne NOT NULL ; et le garde global D41.
    // D41 — hors groupe d'ecriture : l'etablissement vient de la session serveur, pose par
    // `EstablishmentStampProcessor`, jamais du corps de la requete.
    #[Groups(['ressource:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\ManyToOne(targetEntity: Espace::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['ressource:read', 'ressource:write'])]
    private ?Espace $espace = null;

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    #[TypeRessourceValide]
    #[Groups(['ressource:read', 'ressource:write', 'creneau:read'])]
    private string $codeType = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['ressource:read', 'ressource:write', 'creneau:read'])]
    private string $libelle = '';

    #[ORM\Column(type: 'smallint')]
    #[Assert\Positive]
    #[Groups(['ressource:read', 'ressource:write'])]
    private int $capacitePropre = 1;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['ressource:read', 'ressource:write'])]
    private bool $partageable = false;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['ressource:read', 'ressource:write'])]
    private ?self $ressourceMere = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[Groups(['ressource:read', 'ressource:write'])]
    private ?string $competenceRequise = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['ressource:read'])]
    private int $occupationCourante = 0;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['ressource:read', 'ressource:write'])]
    private bool $ouvreAcces = false;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['ressource:read', 'ressource:write'])]
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

    public function getEspace(): ?Espace
    {
        return $this->espace;
    }

    public function setEspace(?Espace $espace): self
    {
        $this->espace = $espace;

        return $this;
    }

    public function getCodeType(): string
    {
        return $this->codeType;
    }

    public function setCodeType(string $codeType): self
    {
        $this->codeType = $codeType;

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

    public function getCapacitePropre(): int
    {
        return $this->capacitePropre;
    }

    public function setCapacitePropre(int $capacitePropre): self
    {
        $this->capacitePropre = $capacitePropre;

        return $this;
    }

    public function isPartageable(): bool
    {
        return $this->partageable;
    }

    public function setPartageable(bool $partageable): self
    {
        $this->partageable = $partageable;

        return $this;
    }

    public function getRessourceMere(): ?self
    {
        return $this->ressourceMere;
    }

    public function setRessourceMere(?self $ressourceMere): self
    {
        $this->ressourceMere = $ressourceMere;

        return $this;
    }

    public function getCompetenceRequise(): ?string
    {
        return $this->competenceRequise;
    }

    public function setCompetenceRequise(?string $competenceRequise): self
    {
        $this->competenceRequise = $competenceRequise;

        return $this;
    }

    public function getOccupationCourante(): int
    {
        return $this->occupationCourante;
    }

    public function setOccupationCourante(int $occupationCourante): self
    {
        $this->occupationCourante = $occupationCourante;

        return $this;
    }

    public function isOuvreAcces(): bool
    {
        return $this->ouvreAcces;
    }

    public function setOuvreAcces(bool $ouvreAcces): self
    {
        $this->ouvreAcces = $ouvreAcces;

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

    /** La ressource porteuse de la jauge globale (RG-M5-08) : elle-même, ou sa ressource mère. */
    public function ressourcePorteuseJauge(): self
    {
        return $this->ressourceMere ?? $this;
    }
}
