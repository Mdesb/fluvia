<?php

declare(strict_types=1);

namespace App\Padel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Padel\Enum\LibellePlageHoraire;
use App\Padel\State\EstablishmentStampProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Plage horaire de tarification (pleine/creuse), axe **propre à Padel** (extension locale, décision
 * structurante n°3 du plan) — l'axe horaire intrajournalier n'existe pas dans le modèle M1
 * `GrilleTarifaire` (produit × type de tarif × saison de dates).
 */
#[ORM\Entity]
#[ORM\Table(name: 'padel_plage_horaire')]
#[ApiResource(
    shortName: 'PadelPlageHoraire',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')"),
        new Get(security: "is_granted('PERM', 'padel.lire')"),
        new Post(security: "is_granted('PERM', 'padel.parametrer')", processor: EstablishmentStampProcessor::class),
        new Patch(security: "is_granted('PERM', 'padel.parametrer')"),
    ],
    normalizationContext: ['groups' => ['plage:read']],
    denormalizationContext: ['groups' => ['plage:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['etablissement' => 'exact', 'libelle' => 'exact'])]
class PlageHoraire
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['plage:read', 'grille:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['plage:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 8, enumType: LibellePlageHoraire::class)]
    #[Groups(['plage:read', 'plage:write', 'grille:read'])]
    private LibellePlageHoraire $libelle = LibellePlageHoraire::Pleine;

    #[ORM\Column(type: 'time_immutable')]
    #[Groups(['plage:read', 'plage:write'])]
    private \DateTimeImmutable $heureDebut;

    #[ORM\Column(type: 'time_immutable')]
    #[Groups(['plage:read', 'plage:write'])]
    private \DateTimeImmutable $heureFin;

    /** @var list<int> jours ISO-8601 (1=lundi..7=dimanche), ≥ 1 élément */
    #[ORM\Column]
    #[Groups(['plage:read', 'plage:write'])]
    private array $joursApplicables = [1, 2, 3, 4, 5, 6, 7];

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->heureDebut = new \DateTimeImmutable('00:00:00');
        $this->heureFin = new \DateTimeImmutable('23:59:59');
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

    public function getLibelle(): LibellePlageHoraire
    {
        return $this->libelle;
    }

    public function setLibelle(LibellePlageHoraire $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getHeureDebut(): \DateTimeImmutable
    {
        return $this->heureDebut;
    }

    public function setHeureDebut(\DateTimeImmutable $heureDebut): self
    {
        $this->heureDebut = $heureDebut;

        return $this;
    }

    public function getHeureFin(): \DateTimeImmutable
    {
        return $this->heureFin;
    }

    public function setHeureFin(\DateTimeImmutable $heureFin): self
    {
        $this->heureFin = $heureFin;

        return $this;
    }

    /** @return list<int> */
    public function getJoursApplicables(): array
    {
        return $this->joursApplicables;
    }

    /** @param list<int> $joursApplicables */
    public function setJoursApplicables(array $joursApplicables): self
    {
        $this->joursApplicables = $joursApplicables;

        return $this;
    }

    /** Vrai si l'instant donné tombe dans cette plage (jour de semaine ISO + heure du jour). */
    public function couvre(\DateTimeImmutable $instant): bool
    {
        $jourIso = (int) $instant->format('N');
        if (!\in_array($jourIso, $this->joursApplicables, true)) {
            return false;
        }
        $heure = $instant->format('H:i:s');

        return $heure >= $this->heureDebut->format('H:i:s') && $heure < $this->heureFin->format('H:i:s');
    }
}
