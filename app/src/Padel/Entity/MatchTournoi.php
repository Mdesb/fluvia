<?php

declare(strict_types=1);

namespace App\Padel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Padel\Enum\StatutMatchTournoi;
use App\Padel\State\SaisirScoreProcessor;
use App\Reservation\Entity\Reservation;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/** Match de tournoi (poule ou tableau), US-PADEL-06. */
#[ORM\Entity]
#[ORM\Table(name: 'padel_match_tournoi')]
#[ApiResource(
    shortName: 'PadelMatchTournoi',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')"),
        new Get(security: "is_granted('PERM', 'padel.lire')"),
        // Corps : { "score": string, "vainqueur": "A"|"B" } (CA-7).
        new Patch(
            uriTemplate: '/padel/matchs/{id}/score',
            input: false,
            security: "is_granted('PERM', 'padel.tournoi_gerer')",
            processor: SaisirScoreProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['match_tournoi:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['tournoi' => 'exact', 'poule' => 'exact', 'statut' => 'exact'])]
class MatchTournoi
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['match_tournoi:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tournoi::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['match_tournoi:read'])]
    private ?Tournoi $tournoi = null;

    #[ORM\ManyToOne(targetEntity: Poule::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['match_tournoi:read'])]
    private ?Poule $poule = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['match_tournoi:read'])]
    private ?int $tour = null;

    #[ORM\ManyToOne(targetEntity: InscriptionTournoi::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['match_tournoi:read'])]
    private ?InscriptionTournoi $paireA = null;

    #[ORM\ManyToOne(targetEntity: InscriptionTournoi::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['match_tournoi:read'])]
    private ?InscriptionTournoi $paireB = null;

    #[ORM\ManyToOne(targetEntity: TerrainPadel::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['match_tournoi:read'])]
    private ?TerrainPadel $terrain = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['match_tournoi:read'])]
    private ?Reservation $reservationBlocage = null;

    #[ORM\Column(length: 60, nullable: true)]
    #[Groups(['match_tournoi:read'])]
    private ?string $score = null;

    #[ORM\ManyToOne(targetEntity: InscriptionTournoi::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['match_tournoi:read'])]
    private ?InscriptionTournoi $vainqueur = null;

    #[ORM\Column(length: 8, enumType: StatutMatchTournoi::class, options: ['default' => 'a_jouer'])]
    #[Groups(['match_tournoi:read'])]
    private StatutMatchTournoi $statut = StatutMatchTournoi::AJouer;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTournoi(): ?Tournoi
    {
        return $this->tournoi;
    }

    public function setTournoi(?Tournoi $tournoi): self
    {
        $this->tournoi = $tournoi;

        return $this;
    }

    public function getPoule(): ?Poule
    {
        return $this->poule;
    }

    public function setPoule(?Poule $poule): self
    {
        $this->poule = $poule;

        return $this;
    }

    public function getTour(): ?int
    {
        return $this->tour;
    }

    public function setTour(?int $tour): self
    {
        $this->tour = $tour;

        return $this;
    }

    public function getPaireA(): ?InscriptionTournoi
    {
        return $this->paireA;
    }

    public function setPaireA(?InscriptionTournoi $paireA): self
    {
        $this->paireA = $paireA;

        return $this;
    }

    public function getPaireB(): ?InscriptionTournoi
    {
        return $this->paireB;
    }

    public function setPaireB(?InscriptionTournoi $paireB): self
    {
        $this->paireB = $paireB;

        return $this;
    }

    public function getTerrain(): ?TerrainPadel
    {
        return $this->terrain;
    }

    public function setTerrain(?TerrainPadel $terrain): self
    {
        $this->terrain = $terrain;

        return $this;
    }

    public function getReservationBlocage(): ?Reservation
    {
        return $this->reservationBlocage;
    }

    public function setReservationBlocage(?Reservation $reservationBlocage): self
    {
        $this->reservationBlocage = $reservationBlocage;

        return $this;
    }

    public function getScore(): ?string
    {
        return $this->score;
    }

    public function setScore(?string $score): self
    {
        $this->score = $score;

        return $this;
    }

    public function getVainqueur(): ?InscriptionTournoi
    {
        return $this->vainqueur;
    }

    public function setVainqueur(?InscriptionTournoi $vainqueur): self
    {
        $this->vainqueur = $vainqueur;

        return $this;
    }

    public function getStatut(): StatutMatchTournoi
    {
        return $this->statut;
    }

    public function setStatut(StatutMatchTournoi $statut): self
    {
        $this->statut = $statut;

        return $this;
    }
}
