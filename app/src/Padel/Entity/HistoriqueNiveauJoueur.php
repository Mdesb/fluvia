<?php

declare(strict_types=1);

namespace App\Padel\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/** Historique append-only des modifications de niveau (RG-SOCLE-07), US-PADEL-04 / CA-5. */
#[ORM\Entity]
#[ORM\Table(name: 'padel_historique_niveau')]
#[ApiResource(
    shortName: 'PadelHistoriqueNiveau',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')"),
        new Get(security: "is_granted('PERM', 'padel.lire')"),
    ],
    normalizationContext: ['groups' => ['historique_niveau:read']],
)]
class HistoriqueNiveauJoueur
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['historique_niveau:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: NiveauJoueur::class, inversedBy: 'historique')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['historique_niveau:read'])]
    private ?NiveauJoueur $niveauJoueur = null;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['historique_niveau:read'])]
    private int $ancienneValeur = 0;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['historique_niveau:read'])]
    private int $nouvelleValeur = 0;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['historique_niveau:read'])]
    private ?Utilisateur $auteur = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['historique_niveau:read'])]
    private \DateTimeImmutable $horodatage;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->horodatage = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNiveauJoueur(): ?NiveauJoueur
    {
        return $this->niveauJoueur;
    }

    public function setNiveauJoueur(?NiveauJoueur $niveauJoueur): self
    {
        $this->niveauJoueur = $niveauJoueur;

        return $this;
    }

    public function getAncienneValeur(): int
    {
        return $this->ancienneValeur;
    }

    public function setAncienneValeur(int $ancienneValeur): self
    {
        $this->ancienneValeur = $ancienneValeur;

        return $this;
    }

    public function getNouvelleValeur(): int
    {
        return $this->nouvelleValeur;
    }

    public function setNouvelleValeur(int $nouvelleValeur): self
    {
        $this->nouvelleValeur = $nouvelleValeur;

        return $this;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): self
    {
        $this->auteur = $auteur;

        return $this;
    }

    public function getHorodatage(): \DateTimeImmutable
    {
        return $this->horodatage;
    }

    public function setHorodatage(\DateTimeImmutable $horodatage): self
    {
        $this->horodatage = $horodatage;

        return $this;
    }
}
