<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Ressource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Exposition : spécialisation musée d'un `Produit` (M1, **réutilisé, non dupliqué** : libellé i18n,
 * TVA `tauxTva`, cycle de publication RG-M1-09, canaux). US-MUSEE-09, RG-MUS-06. Si `aJauge`, le
 * musée **impose** un créneau à l'achat (RG-MUS-01) : la « porte d'entrée » de l'exposition est
 * portée par `ressourceEntree` (`App\Reservation\Entity\Ressource`, module socle Réservation
 * **réutilisé**, décision structurante n°1 du plan) — aucune table de créneau/jauge dupliquée ici.
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_exposition')]
#[ORM\UniqueConstraint(name: 'uniq_exposition_produit', columns: ['produit_id'])]
#[ApiResource(
    shortName: 'MuseeExposition',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
        new Get(security: "is_granted('PERM', 'musee.lire')"),
        new Post(security: "is_granted('PERM', 'musee.configurer') and is_granted('PERM', 'offre.creer')"),
        new Patch(security: "is_granted('PERM', 'musee.configurer') and is_granted('PERM', 'offre.modifier')"),
    ],
    normalizationContext: ['groups' => ['expo:read']],
    denormalizationContext: ['groups' => ['expo:write']],
)]
// `produit` ajoute le 01/09 : sans lui, on ne pouvait pas demander « les dates de ce produit », et
// l'onglet Agenda de la fiche produit n'aurait eu que le vide a montrer. Les deux autres filtres
// repondaient a « les expositions de cet etablissement », pas a celle-la.
#[ApiFilter(SearchFilter::class, properties: ['etablissement' => 'exact', 'aJauge' => 'exact', 'produit' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['aJauge'])]
#[ApiFilter(DateFilter::class, properties: ['dateDebut', 'dateFin'])]
class Exposition
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['expo:read', 'salle:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Assert\NotNull]
    #[Groups(['expo:read', 'expo:write'])]
    private ?Produit $produit = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['expo:read', 'expo:write'])]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['expo:read', 'expo:write'])]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['expo:read', 'expo:write'])]
    private bool $aJauge = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\Positive]
    #[Groups(['expo:read', 'expo:write'])]
    private ?int $jaugeGlobale = null;

    #[ORM\ManyToOne(targetEntity: Ressource::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['expo:read', 'expo:write'])]
    private ?Ressource $ressourceEntree = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['expo:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    public function isAJauge(): bool
    {
        return $this->aJauge;
    }

    public function setAJauge(bool $aJauge): self
    {
        $this->aJauge = $aJauge;

        return $this;
    }

    public function getJaugeGlobale(): ?int
    {
        return $this->jaugeGlobale;
    }

    public function setJaugeGlobale(?int $jaugeGlobale): self
    {
        $this->jaugeGlobale = $jaugeGlobale;

        return $this;
    }

    public function getRessourceEntree(): ?Ressource
    {
        return $this->ressourceEntree;
    }

    public function setRessourceEntree(?Ressource $ressourceEntree): self
    {
        $this->ressourceEntree = $ressourceEntree;

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

    /** Vente ouverte à la date donnée (RG-MUS-06, CA-9) : bornée à [dateDebut, dateFin]. */
    public function venteOuverteA(\DateTimeImmutable $date): bool
    {
        if ($this->dateDebut === null || $this->dateFin === null) {
            return false;
        }
        // Le jour de l'etablissement, comme `Saison::contient()` (#290) : `setTime(0, 0)` sur un instant
        // UTC prenait le jour UTC (mesure le 07/10/2026, `ExhibitionSaleWindowTest`).
        $jour = Etablissement::jourCivil($this->etablissement, $date)->format('Y-m-d');

        return $jour >= $this->dateDebut->format('Y-m-d') && $jour <= $this->dateFin->format('Y-m-d');
    }
}
