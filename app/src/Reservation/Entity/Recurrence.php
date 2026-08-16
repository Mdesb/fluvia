<?php

declare(strict_types=1);

namespace App\Reservation\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Enum\MotifRecurrence;
use App\Reservation\Enum\RegleConflitRecurrence;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Récurrence d'un Créneau (motif hebdo/quotidien/mensuel + fin), RG-M5-07. Lecture seule côté API :
 * la création passe par `POST /reservation/creneaux` (`CreerCreneauProcessor`, expansion RG-M5-07).
 */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_recurrence')]
#[ApiResource(
    shortName: 'ReservationRecurrence',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reservation.lire')"),
        new Get(security: "is_granted('PERM', 'reservation.lire')"),
    ],
    normalizationContext: ['groups' => ['recurrence:read']],
)]
class Recurrence
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['recurrence:read', 'creneau:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['recurrence:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 16, enumType: MotifRecurrence::class)]
    #[Groups(['recurrence:read'])]
    private MotifRecurrence $motif = MotifRecurrence::Hebdomadaire;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['recurrence:read'])]
    private \DateTimeImmutable $finRecurrence;

    /** @var list<int>|null Jours ISO-8601 (1=lundi..7=dimanche), requis si motif hebdomadaire. */
    #[ORM\Column(nullable: true)]
    #[Groups(['recurrence:read'])]
    private ?array $joursSemaine = null;

    #[ORM\Column(length: 20, enumType: RegleConflitRecurrence::class, options: ['default' => 'report_auto'])]
    #[Groups(['recurrence:read'])]
    private RegleConflitRecurrence $regleConflit = RegleConflitRecurrence::ReportAuto;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->finRecurrence = new \DateTimeImmutable('+1 month');
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getMotif(): MotifRecurrence
    {
        return $this->motif;
    }

    public function setMotif(MotifRecurrence $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getFinRecurrence(): \DateTimeImmutable
    {
        return $this->finRecurrence;
    }

    public function setFinRecurrence(\DateTimeImmutable $finRecurrence): self
    {
        $this->finRecurrence = $finRecurrence;

        return $this;
    }

    /** @return list<int> */
    public function getJoursSemaine(): array
    {
        return $this->joursSemaine ?? [];
    }

    /** @param list<int>|null $joursSemaine */
    public function setJoursSemaine(?array $joursSemaine): self
    {
        $this->joursSemaine = $joursSemaine;

        return $this;
    }

    public function getRegleConflit(): RegleConflitRecurrence
    {
        return $this->regleConflit;
    }

    public function setRegleConflit(RegleConflitRecurrence $regleConflit): self
    {
        $this->regleConflit = $regleConflit;

        return $this;
    }
}
