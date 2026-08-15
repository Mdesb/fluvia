<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Compta\Enum\StatutEnvoi;
use App\Compta\State\PreparerEReportingProcessor;
use App\Compta\State\TransmettreEReportingProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Déclaration e-reporting agrégée (US-L4-08, RG-M6-07/08/09) : jour × taux TVA, un seul flux par
 * SIREN, exclut les ventes marquées « ImpayeRegie » (anti-double-comptabilisation).
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_declaration_ereporting')]
#[ApiResource(
    shortName: 'DeclarationEReporting',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(
            uriTemplate: '/compta/e-reporting',
            security: "is_granted('PERM', 'compta.exporter')",
            processor: PreparerEReportingProcessor::class,
        ),
        new Post(
            uriTemplate: '/compta/e-reporting/{id}/transmettre',
            read: true,
            input: false,
            security: "is_granted('PERM', 'compta.exporter')",
            processor: TransmettreEReportingProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['ereport:read']],
    denormalizationContext: ['groups' => ['ereport:write']],
)]
class DeclarationEReporting
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ereport:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ereport:read', 'ereport:write'])]
    private ?ProfilExploitant $profilExploitant = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['ereport:read', 'ereport:write'])]
    private \DateTimeImmutable $periodeDebut;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['ereport:read', 'ereport:write'])]
    private \DateTimeImmutable $periodeFin;

    #[ORM\Column(length: 9)]
    #[Groups(['ereport:read'])]
    private string $siren = '';

    /** @var list<array{jour: string, tauxTvaId: string, baseHTCentimes: int, tvaCentimes: int}> */
    #[ORM\Column]
    #[Groups(['ereport:read'])]
    private array $agregatParJourTaux = [];

    #[ORM\Column(length: 10, enumType: StatutEnvoi::class, options: ['default' => 'prepare'])]
    #[Groups(['ereport:read'])]
    private StatutEnvoi $statutEnvoi = StatutEnvoi::Prepare;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->periodeDebut = new \DateTimeImmutable('first day of this month');
        $this->periodeFin = new \DateTimeImmutable('last day of this month');
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

    public function getPeriodeDebut(): \DateTimeImmutable
    {
        return $this->periodeDebut;
    }

    public function setPeriodeDebut(\DateTimeImmutable $periodeDebut): self
    {
        $this->periodeDebut = $periodeDebut;

        return $this;
    }

    public function getPeriodeFin(): \DateTimeImmutable
    {
        return $this->periodeFin;
    }

    public function setPeriodeFin(\DateTimeImmutable $periodeFin): self
    {
        $this->periodeFin = $periodeFin;

        return $this;
    }

    public function getSiren(): string
    {
        return $this->siren;
    }

    public function setSiren(string $siren): self
    {
        $this->siren = $siren;

        return $this;
    }

    /** @return list<array{jour: string, tauxTvaId: string, baseHTCentimes: int, tvaCentimes: int}> */
    public function getAgregatParJourTaux(): array
    {
        return $this->agregatParJourTaux;
    }

    /** @param list<array{jour: string, tauxTvaId: string, baseHTCentimes: int, tvaCentimes: int}> $agregatParJourTaux */
    public function setAgregatParJourTaux(array $agregatParJourTaux): self
    {
        $this->agregatParJourTaux = $agregatParJourTaux;

        return $this;
    }

    public function getStatutEnvoi(): StatutEnvoi
    {
        return $this->statutEnvoi;
    }

    public function setStatutEnvoi(StatutEnvoi $statutEnvoi): self
    {
        $this->statutEnvoi = $statutEnvoi;

        return $this;
    }
}
