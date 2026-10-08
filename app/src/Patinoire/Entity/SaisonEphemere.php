<?php

declare(strict_types=1);

namespace App\Patinoire\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Offre\Entity\Saison;
use App\Patinoire\State\EstablishmentStampProcessor;
use App\Organisation\Entity\Etablissement;
use App\Patinoire\Enum\BasculeSaisonEphemere;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Saison éphémère (patinoire démontable, RG-PAT-04, US-PATIN-10, §4.9 — gap M1 signalé : le cycle de
 * vie `Produit` générique n'a pas de bascule automatique par date, RG-M1-09 étant manuel). Porte une
 * fenêtre de vente `[fenetreVenteDebut, fenetreVenteFin]` distincte de la fenêtre d'exploitation
 * `[dateOuverture, dateFermeture]`. `catalogueAssocie` est une **liste de références logiques** (uuid,
 * non-FK) vers `App\Offre\Entity\Produit` — même patron que `ParcPatins.produitLocationRef` — plutôt
 * qu'une vraie relation ManyToMany (⚠ divergence documentée vs plan §1, évite de coupler la validation
 * de `Saison`/`Produit` M1 à ce lot). `saisonM1` reste une **vraie FK** vers `App\Offre\Entity\Saison`
 * (réutilise le concept socle tel quel, RG-M1-06).
 */
#[ORM\Entity]
#[ORM\Table(name: 'patin_saison_ephemere')]
#[ApiResource(
    shortName: 'PatinoireSaisonEphemere',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'patinoire.lire')"),
        new Get(security: "is_granted('PERM', 'patinoire.lire')"),
        new Post(security: "is_granted('PERM', 'patinoire.configurer')", processor: EstablishmentStampProcessor::class),
        new Patch(security: "is_granted('PERM', 'patinoire.configurer')"),
    ],
    normalizationContext: ['groups' => ['saison_ephemere:read']],
    denormalizationContext: ['groups' => ['saison_ephemere:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['actif' => 'exact', 'etablissement' => 'exact'])]
class SaisonEphemere
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['saison_ephemere:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['saison_ephemere:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['saison_ephemere:read', 'saison_ephemere:write'])]
    private string $libelle = '';

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['saison_ephemere:read', 'saison_ephemere:write'])]
    private ?\DateTimeImmutable $dateOuverture = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['saison_ephemere:read', 'saison_ephemere:write'])]
    private ?\DateTimeImmutable $dateFermeture = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['saison_ephemere:read', 'saison_ephemere:write'])]
    private ?\DateTimeImmutable $fenetreVenteDebut = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['saison_ephemere:read', 'saison_ephemere:write'])]
    private ?\DateTimeImmutable $fenetreVenteFin = null;

    #[ORM\ManyToOne(targetEntity: Saison::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['saison_ephemere:read', 'saison_ephemere:write'])]
    private ?Saison $saisonM1 = null;

    /** @var list<string> Réf. logiques Produit (M1), non-FK (§8, patron `produitLocationRef`). */
    #[ORM\Column(nullable: true)]
    #[Groups(['saison_ephemere:read', 'saison_ephemere:write'])]
    private ?array $catalogueAssocie = null;

    #[ORM\Column(length: 12, enumType: BasculeSaisonEphemere::class, options: ['default' => 'automatique'])]
    #[Groups(['saison_ephemere:read', 'saison_ephemere:write'])]
    private BasculeSaisonEphemere $bascule = BasculeSaisonEphemere::Automatique;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['saison_ephemere:read', 'saison_ephemere:write'])]
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

    public function getDateOuverture(): ?\DateTimeImmutable
    {
        return $this->dateOuverture;
    }

    public function setDateOuverture(?\DateTimeImmutable $dateOuverture): self
    {
        $this->dateOuverture = $dateOuverture;

        return $this;
    }

    public function getDateFermeture(): ?\DateTimeImmutable
    {
        return $this->dateFermeture;
    }

    public function setDateFermeture(?\DateTimeImmutable $dateFermeture): self
    {
        $this->dateFermeture = $dateFermeture;

        return $this;
    }

    public function getFenetreVenteDebut(): ?\DateTimeImmutable
    {
        return $this->fenetreVenteDebut;
    }

    public function setFenetreVenteDebut(?\DateTimeImmutable $fenetreVenteDebut): self
    {
        $this->fenetreVenteDebut = $fenetreVenteDebut;

        return $this;
    }

    public function getFenetreVenteFin(): ?\DateTimeImmutable
    {
        return $this->fenetreVenteFin;
    }

    public function setFenetreVenteFin(?\DateTimeImmutable $fenetreVenteFin): self
    {
        $this->fenetreVenteFin = $fenetreVenteFin;

        return $this;
    }

    public function getSaisonM1(): ?Saison
    {
        return $this->saisonM1;
    }

    public function setSaisonM1(?Saison $saisonM1): self
    {
        $this->saisonM1 = $saisonM1;

        return $this;
    }

    /** @return list<string> */
    public function getCatalogueAssocie(): array
    {
        return $this->catalogueAssocie ?? [];
    }

    /** @param list<string>|null $catalogueAssocie */
    public function setCatalogueAssocie(?array $catalogueAssocie): self
    {
        $this->catalogueAssocie = $catalogueAssocie;

        return $this;
    }

    public function getBascule(): BasculeSaisonEphemere
    {
        return $this->bascule;
    }

    public function setBascule(BasculeSaisonEphemere $bascule): self
    {
        $this->bascule = $bascule;

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

    /**
     * Vrai si le JOUR de la date, à l'heure de l'établissement, est dans la fenêtre de vente
     * [début, fin] (bornes incluses), RG-PAT-04 : le premier et le dernier jour se vendent en entier.
     */
    public function fenetreVenteOuverteA(\DateTimeImmutable $date): bool
    {
        if ($this->fenetreVenteDebut === null || $this->fenetreVenteFin === null) {
            return true;
        }

        // ⚠ MEME REGLE QUE `Saison::contient()` (#290) : des jours de l'etablissement, pas le jour UTC.
        // Le listener prenait `today`, minuit UTC : entre minuit a Paris et minuit UTC, la vente restait
        // ouverte le lendemain du dernier jour et fermee le premier jour. Mesure le 07/10/2026
        // (`EphemeralSeasonSaleWindowTest`).
        $jour = Etablissement::jourCivil($this->etablissement, $date)->format('Y-m-d');

        return $jour >= $this->fenetreVenteDebut->format('Y-m-d') && $jour <= $this->fenetreVenteFin->format('Y-m-d');
    }
}
