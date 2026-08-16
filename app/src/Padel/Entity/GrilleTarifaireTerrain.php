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
use App\Padel\Enum\StatutJoueurTarif;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Grille tarifaire terrain (RG-PADEL-02) : résout `(terrain, plageHoraire, statutJoueur, dureeMinutes)`
 * → `prix`. Le prix réel est appliqué en « prix forcé » sur la `LigneVente` M2 (§1.2 du plan) —
 * aucune ligne `GrilleTarifaire` M1 n'est créée ni lue pour ce calcul.
 */
#[ORM\Entity]
#[ORM\Table(name: 'padel_grille_tarifaire_terrain')]
#[ORM\UniqueConstraint(name: 'uniq_grille_terrain_plage_statut_duree', columns: ['terrain_id', 'plage_horaire_id', 'statut_joueur', 'duree_minutes'])]
#[ApiResource(
    shortName: 'PadelGrilleTarifaireTerrain',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')"),
        new Get(security: "is_granted('PERM', 'padel.lire')"),
        new Post(security: "is_granted('PERM', 'padel.parametrer')"),
        new Patch(security: "is_granted('PERM', 'padel.parametrer')"),
    ],
    normalizationContext: ['groups' => ['grille:read']],
    denormalizationContext: ['groups' => ['grille:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['terrain' => 'exact', 'statutJoueur' => 'exact'])]
class GrilleTarifaireTerrain
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['grille:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: TerrainPadel::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['grille:read', 'grille:write'])]
    private ?TerrainPadel $terrain = null;

    #[ORM\ManyToOne(targetEntity: PlageHoraire::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['grille:read', 'grille:write'])]
    private ?PlageHoraire $plageHoraire = null;

    #[ORM\Column(length: 11, enumType: StatutJoueurTarif::class)]
    #[Groups(['grille:read', 'grille:write'])]
    private StatutJoueurTarif $statutJoueur = StatutJoueurTarif::NonMembre;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Choice(choices: [60, 90])]
    #[Groups(['grille:read', 'grille:write'])]
    private int $dureeMinutes = 60;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['grille:read', 'grille:write'])]
    private string $prix = '0.00';

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getPlageHoraire(): ?PlageHoraire
    {
        return $this->plageHoraire;
    }

    public function setPlageHoraire(?PlageHoraire $plageHoraire): self
    {
        $this->plageHoraire = $plageHoraire;

        return $this;
    }

    public function getStatutJoueur(): StatutJoueurTarif
    {
        return $this->statutJoueur;
    }

    public function setStatutJoueur(StatutJoueurTarif $statutJoueur): self
    {
        $this->statutJoueur = $statutJoueur;

        return $this;
    }

    public function getDureeMinutes(): int
    {
        return $this->dureeMinutes;
    }

    public function setDureeMinutes(int $dureeMinutes): self
    {
        $this->dureeMinutes = $dureeMinutes;

        return $this;
    }

    public function getPrix(): string
    {
        return $this->prix;
    }

    public function setPrix(string $prix): self
    {
        $this->prix = $prix;

        return $this;
    }
}
