<?php

declare(strict_types=1);

namespace App\Sport\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Securite\Entity\Utilisateur;
use App\Sport\Enum\CanalResolutionImpaye;
use App\Sport\Enum\StatutIncidentPrelevement;
use App\Sport\State\ForcerReouvertureProcessor;
use App\Sport\State\ResoudreImpayeProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/** Dossier impayé (US-SPORT-05/06/07, RG-SPORT-01/02/03, §0/§4.5 du plan). */
#[ORM\Entity]
#[ORM\Table(name: 'sport_incident_prelevement')]
#[ApiResource(
    shortName: 'IncidentPrelevement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sport.piloter_impayes')"),
        new Get(security: "is_granted('PERM', 'sport.piloter_impayes') or (is_granted('PERM', 'sport.lire_soi') and object.getAbonnement().estLieA(user))"),
        new Post(
            uriTemplate: '/sport/impayes/{id}/resoudre',
            read: true,
            input: false,
            security: "is_granted('PERM', 'sport.piloter_impayes') or (is_granted('PERM', 'sport.resoudre_impaye_soi') and object.getAbonnement().estLieA(user))",
            processor: ResoudreImpayeProcessor::class,
        ),
        new Post(
            uriTemplate: '/sport/impayes/{id}/forcer-reouverture',
            read: true,
            input: false,
            security: "is_granted('PERM', 'sport.forcer_acces')",
            processor: ForcerReouvertureProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['incident:read']],
)]
class IncidentPrelevement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['incident:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AbonnementFitness::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['incident:read'])]
    private ?AbonnementFitness $abonnement = null;

    #[ORM\ManyToOne(targetEntity: EcheanceSepa::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['incident:read'])]
    private ?EcheanceSepa $echeanceOrigine = null;

    #[ORM\ManyToOne(targetEntity: RejetPrelevement::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['incident:read'])]
    private ?RejetPrelevement $rejetOrigine = null;

    #[ORM\Column]
    #[Groups(['incident:read'])]
    private int $montantCentimes = 0;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['incident:read'])]
    private \DateTimeImmutable $dateRejet;

    #[ORM\Column(length: 4)]
    #[Groups(['incident:read'])]
    private string $motifBancaire = '';

    #[ORM\Column(length: 14, enumType: StatutIncidentPrelevement::class, options: ['default' => 'representation'])]
    #[Groups(['incident:read'])]
    private StatutIncidentPrelevement $statut = StatutIncidentPrelevement::Representation;

    #[ORM\Column(length: 10, nullable: true, enumType: CanalResolutionImpaye::class)]
    #[Groups(['incident:read'])]
    private ?CanalResolutionImpaye $canalResolution = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['incident:read'])]
    private ?\DateTimeImmutable $dateResolution = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['incident:read'])]
    private ?Utilisateur $reouvertureForceePar = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['incident:read'])]
    private ?string $motifReouvertureForcee = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAbonnement(): ?AbonnementFitness
    {
        return $this->abonnement;
    }

    public function setAbonnement(?AbonnementFitness $abonnement): self
    {
        $this->abonnement = $abonnement;

        return $this;
    }

    public function getEcheanceOrigine(): ?EcheanceSepa
    {
        return $this->echeanceOrigine;
    }

    public function setEcheanceOrigine(?EcheanceSepa $echeanceOrigine): self
    {
        $this->echeanceOrigine = $echeanceOrigine;

        return $this;
    }

    public function getRejetOrigine(): ?RejetPrelevement
    {
        return $this->rejetOrigine;
    }

    public function setRejetOrigine(?RejetPrelevement $rejetOrigine): self
    {
        $this->rejetOrigine = $rejetOrigine;

        return $this;
    }

    public function getMontantCentimes(): int
    {
        return $this->montantCentimes;
    }

    public function setMontantCentimes(int $montantCentimes): self
    {
        $this->montantCentimes = $montantCentimes;

        return $this;
    }

    public function getDateRejet(): \DateTimeImmutable
    {
        return $this->dateRejet;
    }

    public function setDateRejet(\DateTimeImmutable $dateRejet): self
    {
        $this->dateRejet = $dateRejet;

        return $this;
    }

    public function getMotifBancaire(): string
    {
        return $this->motifBancaire;
    }

    public function setMotifBancaire(string $motifBancaire): self
    {
        $this->motifBancaire = $motifBancaire;

        return $this;
    }

    public function getStatut(): StatutIncidentPrelevement
    {
        return $this->statut;
    }

    public function setStatut(StatutIncidentPrelevement $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getCanalResolution(): ?CanalResolutionImpaye
    {
        return $this->canalResolution;
    }

    public function setCanalResolution(?CanalResolutionImpaye $canalResolution): self
    {
        $this->canalResolution = $canalResolution;

        return $this;
    }

    public function getDateResolution(): ?\DateTimeImmutable
    {
        return $this->dateResolution;
    }

    public function setDateResolution(?\DateTimeImmutable $dateResolution): self
    {
        $this->dateResolution = $dateResolution;

        return $this;
    }

    public function getReouvertureForceePar(): ?Utilisateur
    {
        return $this->reouvertureForceePar;
    }

    public function setReouvertureForceePar(?Utilisateur $reouvertureForceePar): self
    {
        $this->reouvertureForceePar = $reouvertureForceePar;

        return $this;
    }

    public function getMotifReouvertureForcee(): ?string
    {
        return $this->motifReouvertureForcee;
    }

    public function setMotifReouvertureForcee(?string $motifReouvertureForcee): self
    {
        $this->motifReouvertureForcee = $motifReouvertureForcee;

        return $this;
    }
}
