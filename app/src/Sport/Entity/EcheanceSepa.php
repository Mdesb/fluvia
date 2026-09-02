<?php

declare(strict_types=1);

namespace App\Sport\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Sepa\Entity\RemiseSepa;
use App\Sport\Enum\StatutEcheanceSepa;
use App\Sport\State\CancelScheduledDebitProcessor;
use App\Sport\State\SimulerRejetProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** Échéance de l'échéancier SEPA d'un abonnement (§1.2 du plan). */
#[ORM\Entity]
#[ORM\Table(name: 'sport_echeance_sepa')]
#[ApiResource(
    shortName: 'EcheanceSepa',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sport.lire') or is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'sport.lire') or is_granted('PERM', 'compta.lire')"),
        new Post(
            uriTemplate: '/sport/echeances/{id}/simuler-rejet',
            read: true,
            input: false,
            security: "is_granted('PERM', 'recouvrement.piloter')",
            processor: SimulerRejetProcessor::class,
            output: IncidentImpaye::class,
            normalizationContext: ['groups' => ['incident:read']],
        ),
        new Post(
            uriTemplate: '/sport/echeances/{id}/annuler',
            read: true,
            input: false,
            security: "is_granted('PERM', 'sport.gerer_abonnement')",
            processor: CancelScheduledDebitProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['echeance:read']],
)]
class EcheanceSepa
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['echeance:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AbonnementFitness::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['echeance:read'])]
    private ?AbonnementFitness $abonnement = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['echeance:read'])]
    private \DateTimeImmutable $dateProgrammee;

    #[ORM\Column]
    #[Assert\Positive]
    #[Groups(['echeance:read'])]
    private int $montantCentimes = 0;

    #[ORM\Column(length: 10, enumType: StatutEcheanceSepa::class, options: ['default' => 'a_venir'])]
    #[Groups(['echeance:read'])]
    private StatutEcheanceSepa $statut = StatutEcheanceSepa::AVenir;

    #[ORM\ManyToOne(targetEntity: RemiseSepa::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['echeance:read'])]
    private ?RemiseSepa $remise = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['echeance:read'])]
    private ?\DateTimeImmutable $dateExecutionReelle = null;

    /**
     * Pourquoi cette échéance a été abandonnée.
     *
     * ⚠ EXIGÉ À L'ÉCRITURE, PAS PROPOSÉ. Une échéance annulée est une somme que le club
     * n'encaissera jamais ; la seule question posée six mois plus tard sera « pourquoi ? », et un
     * état sans motif y répond « on ne sait pas ». Le contrôle vit dans
     * `CancelScheduledDebitProcessor`, seul chemin qui pose `Annulee`.
     *
     * Nullable parce que les échéances qui ne sont pas annulées n'en ont pas.
     */
    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['echeance:read'])]
    private ?string $cancellationReason = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['echeance:read'])]
    private ?\DateTimeImmutable $cancelledAt = null;

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

    public function getDateProgrammee(): \DateTimeImmutable
    {
        return $this->dateProgrammee;
    }

    public function setDateProgrammee(\DateTimeImmutable $dateProgrammee): self
    {
        $this->dateProgrammee = $dateProgrammee;

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

    public function getStatut(): StatutEcheanceSepa
    {
        return $this->statut;
    }

    public function setStatut(StatutEcheanceSepa $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getRemise(): ?RemiseSepa
    {
        return $this->remise;
    }

    public function setRemise(?RemiseSepa $remise): self
    {
        $this->remise = $remise;

        return $this;
    }

    public function getDateExecutionReelle(): ?\DateTimeImmutable
    {
        return $this->dateExecutionReelle;
    }

    public function setDateExecutionReelle(?\DateTimeImmutable $dateExecutionReelle): self
    {
        $this->dateExecutionReelle = $dateExecutionReelle;

        return $this;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function setCancellationReason(?string $cancellationReason): self
    {
        $this->cancellationReason = $cancellationReason;

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): self
    {
        $this->cancelledAt = $cancelledAt;

        return $this;
    }
}
