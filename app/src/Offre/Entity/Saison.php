<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Offre\State\SuppressionReferentielProcessor;
use App\Offre\Validator as OffreAssert;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Saison : période de validité d'un prix (RG-M1-01). En cas de chevauchement pour une date,
 * la saison de priorité supérieure l'emporte (RG-M1-06 / CA-12). Deux saisons de MÊME priorité
 * ne peuvent se chevaucher (CA-9). Non supprimable si utilisée (désactivation seule, CA-9).
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_saison')]
#[UniqueEntity(fields: ['nom'], message: 'Une saison porte déjà ce nom.')]
#[OffreAssert\SaisonSansChevauchement]
#[ApiResource(
    shortName: 'Saison',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'offre.lire')"),
        new Get(security: "is_granted('PERM', 'offre.lire')"),
        new Post(security: "is_granted('PERM', 'offre.gerer')"),
        new Patch(security: "is_granted('PERM', 'offre.gerer')"),
        new Delete(
            security: "is_granted('PERM', 'offre.gerer')",
            processor: SuppressionReferentielProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['ref:read']],
    denormalizationContext: ['groups' => ['ref:write']],
)]
class Saison
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ref:read', 'grille:read', 'produit:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['ref:read', 'ref:write', 'grille:read', 'produit:read'])]
    private string $nom = '';

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['ref:read', 'ref:write', 'grille:read'])]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Assert\Expression(
        'this.getDateFin() === null or this.getDateDebut() === null or this.getDateFin() >= this.getDateDebut()',
        message: 'La date de fin doit être postérieure ou égale à la date de début.'
    )]
    #[Groups(['ref:read', 'ref:write', 'grille:read'])]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['ref:read', 'ref:write', 'grille:read'])]
    private int $priorite = 0;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['ref:read', 'ref:write'])]
    private bool $recurrenceAnnuelle = false;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['ref:read', 'ref:write'])]
    private bool $actif = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

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

    public function getPriorite(): int
    {
        return $this->priorite;
    }

    public function setPriorite(int $priorite): self
    {
        $this->priorite = $priorite;

        return $this;
    }

    public function isRecurrenceAnnuelle(): bool
    {
        return $this->recurrenceAnnuelle;
    }

    public function setRecurrenceAnnuelle(bool $recurrenceAnnuelle): self
    {
        $this->recurrenceAnnuelle = $recurrenceAnnuelle;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }

    /** Vrai si la date fournie tombe dans l'intervalle [dateDebut, dateFin] (bornes incluses). */
    public function contient(\DateTimeImmutable $date): bool
    {
        if ($this->dateDebut === null || $this->dateFin === null) {
            return false;
        }

        return $date >= $this->dateDebut && $date <= $this->dateFin;
    }

    /** Vrai si les intervalles de dates se recouvrent (bornes incluses). */
    public function chevauche(self $autre): bool
    {
        if ($this->dateDebut === null || $this->dateFin === null
            || $autre->dateDebut === null || $autre->dateFin === null) {
            return false;
        }

        return $this->dateDebut <= $autre->dateFin && $autre->dateDebut <= $this->dateFin;
    }
}
