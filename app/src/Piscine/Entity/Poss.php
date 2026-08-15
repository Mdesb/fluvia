<?php

declare(strict_types=1);

namespace App\Piscine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Enum\ModeSeuil;
use App\Piscine\Enum\PerimetrePoss;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POSS = capacité d'accueil réglementaire (RG-PISC-01, US-L6-02). Spécialisation piscine de la jauge
 * FMI générique de L3 : **délègue** seuil/mode/pré-alerte à l'`EspaceAcces` référencé (aucune
 * duplication, plan §1.1/§2.1). `PossModeSeuilGuard` garantit que cet `EspaceAcces` reste en
 * `modeSeuil = blocage` (RG-PISC-01 impose le blocage strict, contrairement au générique L3
 * paramétrable blocage/alerte).
 *
 * ⚠ Point réglementaire ERP/POSS à valider avec l'exploitant (spec §7, priorité haute) : granularité
 * établissement/bassin et texte réglementaire exact hors périmètre applicatif (`baseReglementaire`
 * informatif seulement).
 */
#[ORM\Entity]
#[ORM\Table(name: 'piscine_poss')]
#[ORM\UniqueConstraint(name: 'uniq_poss_espace_acces', columns: ['espace_acces_id'])]
#[ApiResource(
    shortName: 'Poss',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'piscine.lire')"),
        new Get(security: "is_granted('PERM', 'piscine.lire')"),
        new Post(security: "is_granted('PERM', 'piscine.configurer')"),
        new Patch(security: "is_granted('PERM', 'piscine.configurer')"),
    ],
    normalizationContext: ['groups' => ['poss:read']],
    denormalizationContext: ['groups' => ['poss:write']],
)]
class Poss
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['poss:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(name: 'espace_acces_id', nullable: false)]
    #[Assert\NotNull(message: 'Un espace d\'accès L3 est requis (délégation seuil/mode/pré-alerte).')]
    #[Groups(['poss:read', 'poss:write'])]
    private ?EspaceAcces $espaceAcces = null;

    #[ORM\Column(length: 13, enumType: PerimetrePoss::class)]
    #[Assert\NotNull]
    #[Groups(['poss:read', 'poss:write'])]
    private ?PerimetrePoss $perimetre = null;

    #[ORM\ManyToOne(targetEntity: Bassin::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['poss:read', 'poss:write'])]
    private ?Bassin $bassin = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['poss:read', 'poss:write'])]
    private ?string $baseReglementaire = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['poss:read', 'poss:write'])]
    private bool $reservationsProtegees = true;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['poss:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEspaceAcces(): ?EspaceAcces
    {
        return $this->espaceAcces;
    }

    public function setEspaceAcces(?EspaceAcces $espaceAcces): self
    {
        $this->espaceAcces = $espaceAcces;
        if ($espaceAcces !== null) {
            $this->etablissement = $espaceAcces->getEtablissement();
        }

        return $this;
    }

    public function getPerimetre(): ?PerimetrePoss
    {
        return $this->perimetre;
    }

    public function setPerimetre(?PerimetrePoss $perimetre): self
    {
        $this->perimetre = $perimetre;

        return $this;
    }

    public function getBassin(): ?Bassin
    {
        return $this->bassin;
    }

    public function setBassin(?Bassin $bassin): self
    {
        $this->bassin = $bassin;

        return $this;
    }

    public function getBaseReglementaire(): ?string
    {
        return $this->baseReglementaire;
    }

    public function setBaseReglementaire(?string $baseReglementaire): self
    {
        $this->baseReglementaire = $baseReglementaire;

        return $this;
    }

    public function isReservationsProtegees(): bool
    {
        return $this->reservationsProtegees;
    }

    public function setReservationsProtegees(bool $reservationsProtegees): self
    {
        $this->reservationsProtegees = $reservationsProtegees;

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

    /** Délégation — seuil POSS = seuil FMI de l'espace référencé (pas de duplication, plan §1.1). */
    #[Groups(['poss:read'])]
    public function getSeuil(): int
    {
        return $this->espaceAcces?->getSeuilFmi() ?? 0;
    }

    /** Délégation — mode toujours `blocage` en pratique (garanti par `PossModeSeuilGuard`). */
    #[Groups(['poss:read'])]
    public function getModeSeuil(): ?ModeSeuil
    {
        return $this->espaceAcces?->getModeSeuil();
    }

    /** Délégation — pré-alerte portée par `EspaceAcces.preAlertePct` (US-L6-03, plan §0 point 2). */
    #[Groups(['poss:read'])]
    public function getPreAlertePct(): ?int
    {
        return $this->espaceAcces?->getPreAlertePct();
    }
}
