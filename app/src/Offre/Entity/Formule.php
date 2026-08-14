<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use App\Offre\Enum\PeriodiciteFormule;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formule d'abonnement/adhésion : droits d'accès + services inclus à quota (RG-M1-03).
 * Facette optionnelle d'un Produit dont le type porte la facette « formule ».
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_formule')]
class Formule
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['produit:read', 'formule:read'])]
    private Uuid $id;

    /** @var array<string, mixed> {mode: illimite|quota_passages|plage_horaire, n?, plage?}. */
    #[ORM\Column]
    #[Groups(['produit:read', 'produit:write', 'formule:read', 'formule:write'])]
    private array $droitAcces = ['mode' => 'illimite'];

    /** @var Collection<int, ServiceInclus> */
    #[ORM\OneToMany(targetEntity: ServiceInclus::class, mappedBy: 'formule', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['produit:read', 'produit:write', 'formule:read', 'formule:write'])]
    private Collection $servicesInclus;

    #[ORM\Column(length: 16, enumType: PeriodiciteFormule::class)]
    #[Assert\NotNull]
    #[Groups(['produit:read', 'produit:write', 'formule:read', 'formule:write'])]
    private ?PeriodiciteFormule $periodicite = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['produit:read', 'produit:write', 'formule:read', 'formule:write'])]
    private bool $sepaActif = false;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['produit:read', 'produit:write', 'formule:read', 'formule:write'])]
    private ?int $jourPrelevement = null;

    /** @var array<string, mixed> {auto, prix: fixe|evolutif, nbRenouvellements?, emailNotif}. */
    #[ORM\Column]
    #[Groups(['produit:read', 'produit:write', 'formule:read', 'formule:write'])]
    private array $renouvellement = ['auto' => true, 'prix' => 'fixe'];

    /** @var array<string, mixed>|null {dureeMin, pause, resiliation}. */
    #[ORM\Column(nullable: true)]
    #[Groups(['produit:read', 'produit:write', 'formule:read', 'formule:write'])]
    private ?array $engagement = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['produit:read', 'produit:write', 'formule:read', 'formule:write'])]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['produit:read', 'produit:write', 'formule:read', 'formule:write'])]
    private ?\DateTimeImmutable $dateFin = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->servicesInclus = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    /** @return array<string, mixed> */
    public function getDroitAcces(): array
    {
        return $this->droitAcces;
    }

    /** @param array<string, mixed> $droitAcces */
    public function setDroitAcces(array $droitAcces): self
    {
        $this->droitAcces = $droitAcces;

        return $this;
    }

    /** @return Collection<int, ServiceInclus> */
    public function getServicesInclus(): Collection
    {
        return $this->servicesInclus;
    }

    public function addServiceInclus(ServiceInclus $service): self
    {
        if (!$this->servicesInclus->contains($service)) {
            $this->servicesInclus->add($service);
            $service->setFormule($this);
        }

        return $this;
    }

    public function removeServiceInclus(ServiceInclus $service): self
    {
        $this->servicesInclus->removeElement($service);

        return $this;
    }

    public function getPeriodicite(): ?PeriodiciteFormule
    {
        return $this->periodicite;
    }

    public function setPeriodicite(?PeriodiciteFormule $periodicite): self
    {
        $this->periodicite = $periodicite;

        return $this;
    }

    public function isSepaActif(): bool
    {
        return $this->sepaActif;
    }

    public function setSepaActif(bool $sepaActif): self
    {
        $this->sepaActif = $sepaActif;

        return $this;
    }

    public function getJourPrelevement(): ?int
    {
        return $this->jourPrelevement;
    }

    public function setJourPrelevement(?int $jourPrelevement): self
    {
        $this->jourPrelevement = $jourPrelevement;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getRenouvellement(): array
    {
        return $this->renouvellement;
    }

    /** @param array<string, mixed> $renouvellement */
    public function setRenouvellement(array $renouvellement): self
    {
        $this->renouvellement = $renouvellement;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getEngagement(): ?array
    {
        return $this->engagement;
    }

    /** @param array<string, mixed>|null $engagement */
    public function setEngagement(?array $engagement): self
    {
        $this->engagement = $engagement;

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
}
