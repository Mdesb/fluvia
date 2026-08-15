<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Compta\Enum\StatutPeriode;
use App\Compta\State\CloturerPeriodeProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Période/exercice comptable (§4.10 spec, RG-CLOTURE-10). La clôture est irréversible : fige
 * définitivement les écritures de la période (§4.2 : seule une extourne, sur une période ouverte,
 * permet une correction).
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_periode_comptable')]
#[ApiResource(
    shortName: 'PeriodeComptable',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(security: "is_granted('PERM', 'compta.gerer')"),
        new Post(
            uriTemplate: '/compta/periodes/{id}/cloturer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'compta.cloturer')",
            processor: CloturerPeriodeProcessor::class,
            normalizationContext: ['groups' => ['periode:read', 'periode:cloture']],
        ),
    ],
    normalizationContext: ['groups' => ['periode:read']],
    denormalizationContext: ['groups' => ['periode:write']],
)]
class PeriodeComptable
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['periode:read', 'ecriture:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['periode:read', 'periode:write'])]
    private ?ProfilExploitant $profilExploitant = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['periode:read', 'periode:write'])]
    private \DateTimeImmutable $dateDebut;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['periode:read', 'periode:write'])]
    private \DateTimeImmutable $dateFin;

    #[ORM\Column(length: 10, enumType: StatutPeriode::class, options: ['default' => 'ouverte'])]
    #[Groups(['periode:read'])]
    private StatutPeriode $statut = StatutPeriode::Ouverte;

    /** @var array<string, mixed>|null Produits/TVA/encaissements/PCA, rempli à la clôture (RG-CLOTURE-10). */
    #[ORM\Column(nullable: true)]
    #[Groups(['periode:read', 'periode:cloture'])]
    private ?array $etatCloture = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateDebut = new \DateTimeImmutable('first day of this month');
        $this->dateFin = new \DateTimeImmutable('last day of this month');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProfilExploitant(): ?ProfilExploitant
    {
        return $this->profilExploitant;
    }

    public function setProfilExploitant(?ProfilExploitant $profilExploitant): self
    {
        $this->profilExploitant = $profilExploitant;

        return $this;
    }

    public function getEtablissement(): ?\App\Organisation\Entity\Etablissement
    {
        return $this->profilExploitant?->getEtablissementPrincipal();
    }

    public function getDateDebut(): \DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTimeImmutable $dateDebut): self
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): \DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(\DateTimeImmutable $dateFin): self
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getStatut(): StatutPeriode
    {
        return $this->statut;
    }

    public function setStatut(StatutPeriode $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getEtatCloture(): ?array
    {
        return $this->etatCloture;
    }

    /** @param array<string, mixed>|null $etatCloture */
    public function setEtatCloture(?array $etatCloture): self
    {
        $this->etatCloture = $etatCloture;

        return $this;
    }

    public function couvre(\DateTimeImmutable $date): bool
    {
        return $date >= $this->dateDebut && $date <= $this->dateFin;
    }

    public function estOuverte(): bool
    {
        return $this->statut === StatutPeriode::Ouverte;
    }
}
