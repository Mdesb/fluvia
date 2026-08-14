<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use App\Offre\Enum\PeriodeQuota;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Service inclus dans une formule, à quota (RG-M1-03). Le quota se décompte en semaine
 * calendaire (lundi→dimanche, sans report — RG-M1-12). activiteRef est une référence logique
 * vers une Activité (M5), sans FK dure (hors périmètre L1).
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_service_inclus')]
class ServiceInclus
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['produit:read', 'formule:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Formule::class, inversedBy: 'servicesInclus')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Formule $formule = null;

    /** Référence logique vers une Activité (M5), pas de FK dure en L1. */
    #[ORM\Column(type: UuidType::NAME)]
    #[Assert\NotNull]
    #[Groups(['produit:read', 'produit:write', 'formule:read', 'formule:write'])]
    private ?Uuid $activiteRef = null;

    #[ORM\Column]
    #[Assert\Positive]
    #[Groups(['produit:read', 'produit:write', 'formule:read', 'formule:write'])]
    private int $quota = 1;

    #[ORM\Column(length: 24, enumType: PeriodeQuota::class, options: ['default' => 'semaine_calendaire'])]
    #[Groups(['produit:read', 'produit:write', 'formule:read', 'formule:write'])]
    private PeriodeQuota $periode = PeriodeQuota::SemaineCalendaire;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFormule(): ?Formule
    {
        return $this->formule;
    }

    public function setFormule(?Formule $formule): self
    {
        $this->formule = $formule;

        return $this;
    }

    public function getActiviteRef(): ?Uuid
    {
        return $this->activiteRef;
    }

    public function setActiviteRef(?Uuid $activiteRef): self
    {
        $this->activiteRef = $activiteRef;

        return $this;
    }

    public function getQuota(): int
    {
        return $this->quota;
    }

    public function setQuota(int $quota): self
    {
        $this->quota = $quota;

        return $this;
    }

    public function getPeriode(): PeriodeQuota
    {
        return $this->periode;
    }

    public function setPeriode(PeriodeQuota $periode): self
    {
        $this->periode = $periode;

        return $this;
    }
}
