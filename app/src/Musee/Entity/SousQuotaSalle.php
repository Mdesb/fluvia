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
use App\Acces\Enum\ModeSeuil;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Sous-quota de salle (US-MUSEE-02, décision actée « Expo à forte affluence », §4.2). Spécialisation
 * musée de la jauge FMI générique de L3 : **délègue** seuil/mode/pré-alerte à l'`EspaceAcces` de la
 * `Salle` référencée (aucune duplication, plan §1.2 — même patron que `Poss` en Piscine). Une `Salle`
 * ne peut porter qu'un seul `SousQuotaSalle` (0..1).
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_sous_quota_salle')]
#[ORM\UniqueConstraint(name: 'uniq_sous_quota_salle', columns: ['salle_id'])]
#[ApiResource(
    shortName: 'MuseeSousQuotaSalle',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
        new Get(security: "is_granted('PERM', 'musee.lire')"),
        new Post(security: "is_granted('PERM', 'musee.configurer')"),
        new Patch(security: "is_granted('PERM', 'musee.configurer')"),
    ],
    normalizationContext: ['groups' => ['sousquota:read']],
    denormalizationContext: ['groups' => ['sousquota:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['salle' => 'exact', 'actif' => 'exact'])]
class SousQuotaSalle
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['sousquota:read', 'delestage:read', 'salle_live:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Salle::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Assert\NotNull(message: 'Une salle avec un espace d\'accès L3 rattaché est requise (délégation seuil/mode, §1.2 du plan).')]
    #[Groups(['sousquota:read', 'sousquota:write', 'salle_live:read'])]
    private ?Salle $salle = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['sousquota:read', 'sousquota:write'])]
    private bool $actif = true;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['sousquota:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSalle(): ?Salle
    {
        return $this->salle;
    }

    public function setSalle(?Salle $salle): self
    {
        $this->salle = $salle;
        if ($salle !== null) {
            $this->etablissement = $salle->getEtablissement();
        }

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

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
    }

    /** Délégation — seuil du sous-quota = seuil FMI de l'espace d'accès de la salle (pas de duplication). */
    #[Groups(['sousquota:read'])]
    public function getSeuil(): int
    {
        return $this->salle?->getEspaceAcces()?->getSeuilFmi() ?? 0;
    }

    /** Délégation — mode blocage/alerte porté par `EspaceAcces.modeSeuil`, paramétrable (décision n°3 du plan). */
    #[Groups(['sousquota:read'])]
    public function getModeSeuil(): ?ModeSeuil
    {
        return $this->salle?->getEspaceAcces()?->getModeSeuil();
    }

    /** Délégation — pré-alerte portée par `EspaceAcces.preAlertePct`. */
    #[Groups(['sousquota:read'])]
    public function getPreAlertePct(): ?int
    {
        return $this->salle?->getEspaceAcces()?->getPreAlertePct();
    }
}
