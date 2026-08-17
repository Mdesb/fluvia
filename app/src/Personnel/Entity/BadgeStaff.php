<?php

declare(strict_types=1);

namespace App\Personnel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Organisation\Entity\Etablissement;
use App\Personnel\Enum\StatutBadgeStaff;
use App\Personnel\State\DeclarerIncidentBadgeProcessor;
use App\Personnel\State\EmissionBadgeStaffProcessor;
use App\Personnel\State\RevocationBadgeProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Badge staff = Support (L3) + DroitAcces (L3) rattachés à un Employé sur un Établissement
 * (RG-PERSO-06, décision n°2 du plan) : **1 badge actif par couple (Employé, Établissement)** — un
 * employé multi-site reçoit un badge physique par site (divergence assumée vs la lettre de la spec
 * §5, cf. plan §0 décision n°2). `statut` est dénormalisé par rapport à `Support.statut` (décision
 * n°7) : `suspendu`/`revoque` mappent tous deux sur `Support.statut = bloque`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'personnel_badge_staff')]
#[ORM\UniqueConstraint(name: 'uniq_badge_cle_active', columns: ['cle_active'])]
#[ApiResource(
    shortName: 'BadgeStaff',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'personnel.lire') or is_granted('PERM', 'personnel.lire_soi')"),
        new Get(security: "is_granted('PERM', 'personnel.lire') or is_granted('EMPLOYE_SOI', object.getEmploye())"),
        new Post(
            uriTemplate: '/personnel/employes/{id}/badges',
            read: false,
            input: false,
            security: "is_granted('PERM', 'personnel.gerer_badge')",
            processor: EmissionBadgeStaffProcessor::class,
        ),
        new Post(
            uriTemplate: '/personnel/badges/{id}/revoquer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'personnel.gerer_badge')",
            processor: RevocationBadgeProcessor::class,
            normalizationContext: ['groups' => ['badge_staff:read']],
        ),
        new Post(
            uriTemplate: '/personnel/badges/{id}/suspendre',
            read: true,
            input: false,
            security: "is_granted('PERM', 'personnel.gerer_badge')",
            processor: RevocationBadgeProcessor::class,
            normalizationContext: ['groups' => ['badge_staff:read']],
        ),
        new Post(
            uriTemplate: '/personnel/badges/{id}/reactiver',
            read: true,
            input: false,
            security: "is_granted('PERM', 'personnel.gerer_badge')",
            processor: RevocationBadgeProcessor::class,
            normalizationContext: ['groups' => ['badge_staff:read']],
        ),
        new Post(
            uriTemplate: '/personnel/badges/{id}/declarer-incident',
            read: true,
            input: false,
            security: "is_granted('PERM', 'personnel.gerer_badge') or is_granted('PERM', 'acces.bloquer_support')",
            processor: DeclarerIncidentBadgeProcessor::class,
            normalizationContext: ['groups' => ['badge_staff:read']],
        ),
    ],
    normalizationContext: ['groups' => ['badge_staff:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['employe' => 'exact', 'etablissement' => 'exact', 'statut' => 'exact'])]
class BadgeStaff
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['badge_staff:read', 'portee_acces:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Employe::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['badge_staff:read', 'roster:read'])]
    private ?Employe $employe = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['badge_staff:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\ManyToOne(targetEntity: Support::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['badge_staff:read'])]
    private ?Support $support = null;

    #[ORM\ManyToOne(targetEntity: DroitAcces::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['badge_staff:read'])]
    private ?DroitAcces $droitAcces = null;

    #[ORM\Column(length: 10, enumType: StatutBadgeStaff::class, options: ['default' => 'actif'])]
    #[Groups(['badge_staff:read'])]
    private StatutBadgeStaff $statut = StatutBadgeStaff::Actif;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['badge_staff:read'])]
    private \DateTimeImmutable $dateEmission;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['badge_staff:read'])]
    private ?\DateTimeImmutable $dateRevocation = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['badge_staff:read'])]
    private ?string $motifRevocation = null;

    /**
     * `employe_id`+`etablissement_id` si `statut = actif`, NULL sinon (unicité 1 badge actif/couple,
     * patron `Appairage.supportActif`). Longueur 80 (deux UUID textuels + séparateur = 73 caractères)
     * — écart mineur assumé vs le `string(72)` indicatif du plan §1 (calcul basé sur la longueur
     * binaire, ici les UUID sont concaténés en représentation texte).
     */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $cleActive = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateEmission = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmploye(): ?Employe
    {
        return $this->employe;
    }

    public function setEmploye(?Employe $employe): self
    {
        $this->employe = $employe;
        $this->synchroniserCleActive();

        return $this;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;
        $this->synchroniserCleActive();

        return $this;
    }

    public function getSupport(): ?Support
    {
        return $this->support;
    }

    public function setSupport(?Support $support): self
    {
        $this->support = $support;

        return $this;
    }

    public function getDroitAcces(): ?DroitAcces
    {
        return $this->droitAcces;
    }

    public function setDroitAcces(?DroitAcces $droitAcces): self
    {
        $this->droitAcces = $droitAcces;

        return $this;
    }

    public function getStatut(): StatutBadgeStaff
    {
        return $this->statut;
    }

    public function setStatut(StatutBadgeStaff $statut): self
    {
        $this->statut = $statut;
        $this->synchroniserCleActive();

        return $this;
    }

    public function getDateEmission(): \DateTimeImmutable
    {
        return $this->dateEmission;
    }

    public function setDateEmission(\DateTimeImmutable $dateEmission): self
    {
        $this->dateEmission = $dateEmission;

        return $this;
    }

    public function getDateRevocation(): ?\DateTimeImmutable
    {
        return $this->dateRevocation;
    }

    public function setDateRevocation(?\DateTimeImmutable $dateRevocation): self
    {
        $this->dateRevocation = $dateRevocation;

        return $this;
    }

    public function getMotifRevocation(): ?string
    {
        return $this->motifRevocation;
    }

    public function setMotifRevocation(?string $motifRevocation): self
    {
        $this->motifRevocation = $motifRevocation;

        return $this;
    }

    public function getCleActive(): ?string
    {
        return $this->cleActive;
    }

    private function synchroniserCleActive(): void
    {
        if ($this->statut === StatutBadgeStaff::Actif && $this->employe !== null && $this->etablissement !== null) {
            $this->cleActive = $this->employe->getId() . '_' . $this->etablissement->getId();
        } else {
            $this->cleActive = null;
        }
    }
}
