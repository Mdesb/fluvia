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
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Activité (catalogue, cahier M5-02) : ce module en est le **propriétaire fonctionnel**, référencée
 * de façon logique par `ServiceInclus.activiteRef` (M1, RG-M1-03). Porte la durée par défaut d'un
 * Créneau et son tarif de référence.
 *
 * ⚠ Divergence pragmatique documentée (constitution §8 DoD point 5) : en sus de
 * `produitTarifReference` (référence FK réelle vers `Produit`, prévue par le plan), ce module porte
 * un champ `tarifReferenceMontant` — la résolution complète du prix via la grille M1
 * (`ResolveurPrix` + `TypeTarif` + `Saison`) est hors périmètre de ce lot socle ; ce montant simple
 * sert de base au calcul de la vente à l'unité (§4.3) et au montant d'un no-show (§4.7).
 */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_activite')]
#[ApiResource(
    shortName: 'ReservationActivite',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reservation.lire')"),
        new Get(security: "is_granted('PERM', 'reservation.lire')"),
        new Post(security: "is_granted('PERM', 'reservation.gerer_ressource')"),
        new Patch(security: "is_granted('PERM', 'reservation.gerer_ressource')"),
    ],
    normalizationContext: ['groups' => ['activite:read']],
    denormalizationContext: ['groups' => ['activite:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['typeActivite' => 'exact', 'actif' => 'exact'])]
class Activite
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['activite:read', 'creneau:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['activite:read', 'activite:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['activite:read', 'activite:write', 'creneau:read'])]
    private string $libelle = '';

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Groups(['activite:read', 'activite:write'])]
    private string $typeActivite = '';

    #[ORM\Column(type: 'smallint')]
    #[Assert\Positive]
    #[Groups(['activite:read', 'activite:write'])]
    private int $dureeMinutes = 60;

    #[ORM\Column(length: 60, nullable: true)]
    #[Groups(['activite:read', 'activite:write'])]
    private ?string $niveauRequis = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[Groups(['activite:read', 'activite:write'])]
    private ?string $competenceExigee = null;

    #[ORM\ManyToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['activite:read', 'activite:write'])]
    private ?Produit $produitTarifReference = null;

    /** Divergence pragmatique documentée en tête de classe. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Assert\PositiveOrZero]
    #[Groups(['activite:read', 'activite:write', 'creneau:read'])]
    private string $tarifReferenceMontant = '0.00';

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['activite:read', 'activite:write'])]
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

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getTypeActivite(): string
    {
        return $this->typeActivite;
    }

    public function setTypeActivite(string $typeActivite): self
    {
        $this->typeActivite = $typeActivite;

        return $this;
    }

    public function getDureeMinutes(): int
    {
        return $this->dureeMinutes;
    }

    public function setDureeMinutes(int $dureeMinutes): self
    {
        $this->dureeMinutes = $dureeMinutes;

        return $this;
    }

    public function getNiveauRequis(): ?string
    {
        return $this->niveauRequis;
    }

    public function setNiveauRequis(?string $niveauRequis): self
    {
        $this->niveauRequis = $niveauRequis;

        return $this;
    }

    public function getCompetenceExigee(): ?string
    {
        return $this->competenceExigee;
    }

    public function setCompetenceExigee(?string $competenceExigee): self
    {
        $this->competenceExigee = $competenceExigee;

        return $this;
    }

    public function getProduitTarifReference(): ?Produit
    {
        return $this->produitTarifReference;
    }

    public function setProduitTarifReference(?Produit $produitTarifReference): self
    {
        $this->produitTarifReference = $produitTarifReference;

        return $this;
    }

    public function getTarifReferenceMontant(): string
    {
        return $this->tarifReferenceMontant;
    }

    public function setTarifReferenceMontant(string $tarifReferenceMontant): self
    {
        $this->tarifReferenceMontant = $tarifReferenceMontant;

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
