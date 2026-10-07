<?php

declare(strict_types=1);

namespace App\Membership\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Securite\Entity\Utilisateur;
use App\Membership\Enum\StatutResiliation;
use App\Membership\State\ValiderMotifLegitimeProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use App\Membership\Entity\Membership;

/**
 * Demande de résiliation (US-SPORT-03, RG-SPORT-06/07). Création via la sous-ressource
 * `POST /sport/abonnements/{id}/resiliations` (déclarée sur `Membership`). En engagement, une
 * demande sans motif légitime est `refusee` immédiatement ; avec motif légitime, elle reste `refusee`
 * (en attente) jusqu'à validation manuelle explicite (`POST /sport/resiliations/{id}/valider-motif-legitime`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sport_resiliation')]
#[ApiResource(
    shortName: 'Resiliation',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sport.lire')"),
        new Get(security: "is_granted('PERM', 'sport.lire')"),
        new \ApiPlatform\Metadata\Post(
            uriTemplate: '/sport/resiliations/{id}/valider-motif-legitime',
            read: true,
            input: false,
            security: "is_granted('PERM', 'sport.gerer_abonnement')",
            processor: ValiderMotifLegitimeProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['resiliation:read']],
)]
class Resiliation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['resiliation:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Membership::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['resiliation:read'])]
    private ?Membership $abonnement = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['resiliation:read'])]
    private \DateTimeImmutable $dateDemande;

    #[ORM\Column(length: 255)]
    #[Groups(['resiliation:read'])]
    private string $motif = '';

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['resiliation:read'])]
    private bool $motifLegitime = false;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['resiliation:read'])]
    private ?string $justificatifChemin = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['resiliation:read'])]
    private ?Utilisateur $valideParUtilisateur = null;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['resiliation:read'])]
    private int $preavisAppliqueJours = 0;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['resiliation:read'])]
    private \DateTimeImmutable $dateEffet;

    #[ORM\Column(length: 12, enumType: StatutResiliation::class, options: ['default' => 'refusee'])]
    #[Groups(['resiliation:read'])]
    private StatutResiliation $statut = StatutResiliation::Refusee;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAbonnement(): ?Membership
    {
        return $this->abonnement;
    }

    public function setAbonnement(?Membership $abonnement): self
    {
        $this->abonnement = $abonnement;

        return $this;
    }

    public function getDateDemande(): \DateTimeImmutable
    {
        return $this->dateDemande;
    }

    public function setDateDemande(\DateTimeImmutable $dateDemande): self
    {
        $this->dateDemande = $dateDemande;

        return $this;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function isMotifLegitime(): bool
    {
        return $this->motifLegitime;
    }

    public function setMotifLegitime(bool $motifLegitime): self
    {
        $this->motifLegitime = $motifLegitime;

        return $this;
    }

    public function getJustificatifChemin(): ?string
    {
        return $this->justificatifChemin;
    }

    public function setJustificatifChemin(?string $justificatifChemin): self
    {
        $this->justificatifChemin = $justificatifChemin;

        return $this;
    }

    public function getValideParUtilisateur(): ?Utilisateur
    {
        return $this->valideParUtilisateur;
    }

    public function setValideParUtilisateur(?Utilisateur $valideParUtilisateur): self
    {
        $this->valideParUtilisateur = $valideParUtilisateur;

        return $this;
    }

    public function getPreavisAppliqueJours(): int
    {
        return $this->preavisAppliqueJours;
    }

    public function setPreavisAppliqueJours(int $preavisAppliqueJours): self
    {
        $this->preavisAppliqueJours = $preavisAppliqueJours;

        return $this;
    }

    public function getDateEffet(): \DateTimeImmutable
    {
        return $this->dateEffet;
    }

    public function setDateEffet(\DateTimeImmutable $dateEffet): self
    {
        $this->dateEffet = $dateEffet;

        return $this;
    }

    public function getStatut(): StatutResiliation
    {
        return $this->statut;
    }

    public function setStatut(StatutResiliation $statut): self
    {
        $this->statut = $statut;

        return $this;
    }
}
