<?php

declare(strict_types=1);

namespace App\Sport\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Sport\Enum\StatutRejetPrelevement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Retour brut normalisé banque (1 par retour reçu, §1.5 du plan) — distinct de l'`IncidentPrelevement`
 * (objet métier du dossier impayé). Un retour sur une représentation met à jour l'incident existant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sport_rejet_prelevement')]
#[ApiResource(
    shortName: 'RejetPrelevement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sport.piloter_impayes') or is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'sport.piloter_impayes') or is_granted('PERM', 'compta.lire')"),
    ],
    normalizationContext: ['groups' => ['rejet:read']],
)]
class RejetPrelevement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['rejet:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: RemiseSepa::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['rejet:read'])]
    private ?RemiseSepa $remise = null;

    #[ORM\ManyToOne(targetEntity: EcheanceSepa::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['rejet:read'])]
    private ?EcheanceSepa $echeance = null;

    #[ORM\Column(length: 4)]
    #[Groups(['rejet:read'])]
    private string $codeRetour = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['rejet:read'])]
    private ?string $libelleRetour = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['rejet:read'])]
    private \DateTimeImmutable $dateReception;

    #[ORM\Column]
    #[Groups(['rejet:read'])]
    private int $montantCentimes = 0;

    #[ORM\Column(length: 10, enumType: StatutRejetPrelevement::class, options: ['default' => 'nouveau'])]
    #[Groups(['rejet:read'])]
    private StatutRejetPrelevement $statut = StatutRejetPrelevement::Nouveau;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateReception = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getEcheance(): ?EcheanceSepa
    {
        return $this->echeance;
    }

    public function setEcheance(?EcheanceSepa $echeance): self
    {
        $this->echeance = $echeance;

        return $this;
    }

    public function getCodeRetour(): string
    {
        return $this->codeRetour;
    }

    public function setCodeRetour(string $codeRetour): self
    {
        $this->codeRetour = $codeRetour;

        return $this;
    }

    public function getLibelleRetour(): ?string
    {
        return $this->libelleRetour;
    }

    public function setLibelleRetour(?string $libelleRetour): self
    {
        $this->libelleRetour = $libelleRetour;

        return $this;
    }

    public function getDateReception(): \DateTimeImmutable
    {
        return $this->dateReception;
    }

    public function setDateReception(\DateTimeImmutable $dateReception): self
    {
        $this->dateReception = $dateReception;

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

    public function getStatut(): StatutRejetPrelevement
    {
        return $this->statut;
    }

    public function setStatut(StatutRejetPrelevement $statut): self
    {
        $this->statut = $statut;

        return $this;
    }
}
