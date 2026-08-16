<?php

declare(strict_types=1);

namespace App\Padel\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/** Poule d'un tournoi (format `poules`), générée automatiquement (US-PADEL-05, CA-6). */
#[ORM\Entity]
#[ORM\Table(name: 'padel_poule')]
#[ApiResource(
    shortName: 'PadelPoule',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')"),
        new Get(security: "is_granted('PERM', 'padel.lire')"),
    ],
    normalizationContext: ['groups' => ['poule:read']],
)]
class Poule
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['poule:read', 'inscription_tournoi:read', 'match_tournoi:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tournoi::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['poule:read'])]
    private ?Tournoi $tournoi = null;

    #[ORM\Column(length: 40)]
    #[Groups(['poule:read'])]
    private string $libelle = '';

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

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }
}
