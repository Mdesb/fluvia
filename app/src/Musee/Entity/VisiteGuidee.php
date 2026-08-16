<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Musee\Enum\StatutVisiteGuidee;
use App\Musee\State\ConfirmerVisiteGuideeProcessor;
use App\Musee\State\CreerVisiteGuideeProcessor;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Creneau;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Visite guidée (US-MUSEE-03, RG-MUS-02) : `creneauVisite` est un `Creneau` (module socle Réservation,
 * **réutilisé**) sur une `Ressource(codeType='visite_guidee')` **dédiée** à cette visite — capacité
 * **indépendante** de la jauge d'entrée de l'exposition (`creneauEntree`, décompte séparé). La
 * confirmation exige un `guide` qualifié dans la langue demandée (§4.3/4.4, CA-3/CA-4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_visite_guidee')]
#[ORM\UniqueConstraint(name: 'uniq_visite_creneau_visite', columns: ['creneau_visite_id'])]
#[ApiResource(
    shortName: 'MuseeVisiteGuidee',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
        new Get(security: "is_granted('PERM', 'musee.lire')"),
        new Post(
            uriTemplate: '/musee/visites-guidees',
            security: "is_granted('PERM', 'musee.gerer_visite')",
            processor: CreerVisiteGuideeProcessor::class,
        ),
        new Patch(security: "is_granted('PERM', 'musee.gerer_visite')"),
        new Post(
            uriTemplate: '/musee/visites-guidees/{id}/confirmer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'musee.gerer_visite')",
            processor: ConfirmerVisiteGuideeProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['visite:read']],
    denormalizationContext: ['groups' => ['visite:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['etablissement' => 'exact', 'langue' => 'exact', 'guide' => 'exact', 'statut' => 'exact'])]
class VisiteGuidee
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['visite:read', 'bascule:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['visite:read', 'visite:write'])]
    private string $theme = '';

    #[ORM\Column(length: 8)]
    #[Assert\NotBlank]
    #[Groups(['visite:read', 'visite:write'])]
    private string $langue = '';

    #[ORM\ManyToOne(targetEntity: Guide::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['visite:read', 'visite:write'])]
    private ?Guide $guide = null;

    #[ORM\OneToOne(targetEntity: Creneau::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Groups(['visite:read'])]
    private ?Creneau $creneauVisite = null;

    #[ORM\ManyToOne(targetEntity: Creneau::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['visite:read', 'visite:write'])]
    private ?Creneau $creneauEntree = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['visite:read', 'visite:write'])]
    private string $pointRDV = '';

    #[ORM\Column(length: 10, enumType: StatutVisiteGuidee::class, options: ['default' => 'planifiee'])]
    #[Groups(['visite:read'])]
    private StatutVisiteGuidee $statut = StatutVisiteGuidee::Planifiee;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['visite:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): self
    {
        $this->theme = $theme;

        return $this;
    }

    public function getLangue(): string
    {
        return $this->langue;
    }

    public function setLangue(string $langue): self
    {
        $this->langue = $langue;

        return $this;
    }

    public function getGuide(): ?Guide
    {
        return $this->guide;
    }

    public function setGuide(?Guide $guide): self
    {
        $this->guide = $guide;

        return $this;
    }

    public function getCreneauVisite(): ?Creneau
    {
        return $this->creneauVisite;
    }

    public function setCreneauVisite(?Creneau $creneauVisite): self
    {
        $this->creneauVisite = $creneauVisite;

        return $this;
    }

    public function getCreneauEntree(): ?Creneau
    {
        return $this->creneauEntree;
    }

    public function setCreneauEntree(?Creneau $creneauEntree): self
    {
        $this->creneauEntree = $creneauEntree;

        return $this;
    }

    public function getPointRDV(): string
    {
        return $this->pointRDV;
    }

    public function setPointRDV(string $pointRDV): self
    {
        $this->pointRDV = $pointRDV;

        return $this;
    }

    public function getStatut(): StatutVisiteGuidee
    {
        return $this->statut;
    }

    public function setStatut(StatutVisiteGuidee $statut): self
    {
        $this->statut = $statut;

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
}
