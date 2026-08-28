<?php

declare(strict_types=1);

namespace App\Opening\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Acces\Entity\EspaceAcces;
use App\Organisation\Entity\Etablissement;
use App\Opening\Enum\OpeningExceptionType;
use App\Opening\State\OpeningWriteProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CE QUE LA DATE FAIT AU PLANNING HEBDOMADAIRE : un jour férié, des travaux, une nocturne.
 *
 * ── LE MOTIF EST OBLIGATOIRE, ET C'EST DÉLIBÉRÉ ─────────────────────────────────────────────────
 *
 * Une fermeture sans raison écrite est une question à laquelle personne ne pourra répondre en
 * décembre, quand quelqu'un demandera pourquoi le site était fermé un mardi de mars. C'est la même
 * règle que le motif de fermeture d'un ticket d'assistance, pour la même raison : ce qui est saisi
 * une fois est relu des mois plus tard, par quelqu'un d'autre.
 *
 * ── SANS HEURES, LA FERMETURE VAUT POUR LA JOURNÉE ENTIÈRE ──────────────────────────────────────
 *
 * C'est le cas le plus fréquent — un férié, une semaine de vidange — et il doit être le plus rapide
 * à saisir. Avec des heures, la fermeture ne mord que sur la tranche indiquée : une coupure d'eau
 * de 14 h à 16 h ne ferme pas la journée.
 *
 * ⚠ Une ouverture exceptionnelle SANS heures n'a pas de sens (« ouvert, mais quand ? ») et est
 * refusée à la validation. Ne pas la refuser aurait produit une journée réputée ouverte 24 h, donc
 * un contrôle d'accès qui laisse passer la nuit.
 */
#[ORM\Entity]
#[ORM\Table(name: 'opening_exception')]
#[ORM\Index(name: 'idx_opening_exception_establishment_date', columns: ['establishment_id', 'exception_date'])]
#[ApiResource(
    shortName: 'OpeningException',
    operations: [
        // Voir `OpeningSlot` : trente par defaut, et une piscine depasse trente jours
        // particuliers en une saison — feries, vidanges et nocturnes confondus.
        new GetCollection(
            security: "is_granted('PERM', 'acces.lire') or is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'reservation.lire')",
            paginationItemsPerPage: 200,
        ),
        new Get(security: "is_granted('PERM', 'acces.lire') or is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'reservation.lire')"),
        new Post(security: "is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'acces.gerer')", processor: OpeningWriteProcessor::class),
        new Patch(security: "is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'acces.gerer')", processor: OpeningWriteProcessor::class),
        new Delete(security: "is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'acces.gerer')"),
    ],
    routePrefix: '/opening',
    normalizationContext: ['groups' => ['opening_exception:read']],
    denormalizationContext: ['groups' => ['opening_exception:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['establishment' => 'exact', 'space' => 'exact', 'type' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['date'])]
class OpeningException
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['opening_exception:read'])]
    private Uuid $id;

    /** Posé par le processor depuis l'établissement actif — jamais lu du corps de requête. */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['opening_exception:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\ManyToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['opening_exception:read', 'opening_exception:write'])]
    private ?EspaceAcces $space = null;

    #[ORM\Column(name: 'exception_date', type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['opening_exception:read', 'opening_exception:write'])]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 32, enumType: OpeningExceptionType::class)]
    #[Groups(['opening_exception:read', 'opening_exception:write'])]
    private OpeningExceptionType $type = OpeningExceptionType::Closure;

    #[ORM\Column(type: 'time_immutable', nullable: true)]
    #[Groups(['opening_exception:read', 'opening_exception:write'])]
    private ?\DateTimeImmutable $startTime = null;

    #[ORM\Column(type: 'time_immutable', nullable: true)]
    #[Groups(['opening_exception:read', 'opening_exception:write'])]
    private ?\DateTimeImmutable $endTime = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'Indiquez le motif : dans six mois, personne ne saura pourquoi ce jour était différent.')]
    #[Groups(['opening_exception:read', 'opening_exception:write'])]
    private string $reason = '';

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEstablishment(): ?Etablissement
    {
        return $this->establishment;
    }

    public function setEstablishment(?Etablissement $establishment): self
    {
        $this->establishment = $establishment;

        return $this;
    }

    public function getSpace(): ?EspaceAcces
    {
        return $this->space;
    }

    public function setSpace(?EspaceAcces $space): self
    {
        $this->space = $space;

        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getType(): OpeningExceptionType
    {
        return $this->type;
    }

    public function setType(OpeningExceptionType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getStartTime(): ?\DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(?\DateTimeImmutable $startTime): self
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getEndTime(): ?\DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(?\DateTimeImmutable $endTime): self
    {
        $this->endTime = $endTime;

        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    /** Vrai si l'exception porte sur la journée entière (aucune heure saisie). */
    #[Groups(['opening_exception:read'])]
    public function isAllDay(): bool
    {
        return $this->startTime === null && $this->endTime === null;
    }
}
