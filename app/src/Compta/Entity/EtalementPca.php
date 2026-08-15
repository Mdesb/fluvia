<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Compta\Enum\MethodePca;
use App\Compta\Enum\NaturePca;
use App\Compta\State\RapprochementPcaProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Étalement PCA (compte 487, RG-M6-02/03) : nature et méthode héritées de `Produit::reglePca` (M1,
 * RG-M1-08). `resteAServirCentimes` décroît à chaque reprise, rapprochable à tout instant (US-L4-05).
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_etalement_pca')]
#[ApiResource(
    shortName: 'EtalementPca',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Get(
            uriTemplate: '/compta/pca/{id}/rapprochement',
            security: "is_granted('PERM', 'compta.lire')",
            provider: RapprochementPcaProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['pca:read']],
)]
class EtalementPca
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['pca:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['pca:read'])]
    private ?ProfilExploitant $profilExploitant = null;

    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['pca:read'])]
    private Uuid $produit;

    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['pca:read'])]
    private Uuid $venteOrigine;

    #[ORM\Column(length: 24, enumType: NaturePca::class)]
    #[Groups(['pca:read'])]
    private NaturePca $nature = NaturePca::AEtaler;

    #[ORM\Column(length: 16, enumType: MethodePca::class)]
    #[Groups(['pca:read'])]
    private MethodePca $methode = MethodePca::ProrataTemporis;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['pca:read'])]
    private ?\DateTimeImmutable $periodeServiceDebut = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['pca:read'])]
    private ?\DateTimeImmutable $periodeServiceFin = null;

    #[ORM\ManyToOne(targetEntity: CompteComptable::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['pca:read'])]
    private ?CompteComptable $compteReport = null;

    #[ORM\Column]
    #[Groups(['pca:read'])]
    private int $montantReporteCentimes = 0;

    #[ORM\Column]
    #[Groups(['pca:read'])]
    private int $resteAServirCentimes = 0;

    /** Nombre d'unités crédité (carte multi-entrées, méthode « au passage »). */
    #[ORM\Column(nullable: true)]
    #[Groups(['pca:read'])]
    private ?int $nbUnitesCarte = null;

    #[ORM\Column(length: 128, nullable: true)]
    #[Groups(['pca:read'])]
    private ?string $identifiantSupport = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['pca:read'])]
    private bool $soldeResiduelTraite = false;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->produit = Uuid::v4();
        $this->venteOrigine = Uuid::v4();
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

    public function getProduit(): Uuid
    {
        return $this->produit;
    }

    public function setProduit(Uuid $produit): self
    {
        $this->produit = $produit;

        return $this;
    }

    public function getVenteOrigine(): Uuid
    {
        return $this->venteOrigine;
    }

    public function setVenteOrigine(Uuid $venteOrigine): self
    {
        $this->venteOrigine = $venteOrigine;

        return $this;
    }

    public function getNature(): NaturePca
    {
        return $this->nature;
    }

    public function setNature(NaturePca $nature): self
    {
        $this->nature = $nature;

        return $this;
    }

    public function getMethode(): MethodePca
    {
        return $this->methode;
    }

    public function setMethode(MethodePca $methode): self
    {
        $this->methode = $methode;

        return $this;
    }

    public function getPeriodeServiceDebut(): ?\DateTimeImmutable
    {
        return $this->periodeServiceDebut;
    }

    public function setPeriodeServiceDebut(?\DateTimeImmutable $periodeServiceDebut): self
    {
        $this->periodeServiceDebut = $periodeServiceDebut;

        return $this;
    }

    public function getPeriodeServiceFin(): ?\DateTimeImmutable
    {
        return $this->periodeServiceFin;
    }

    public function setPeriodeServiceFin(?\DateTimeImmutable $periodeServiceFin): self
    {
        $this->periodeServiceFin = $periodeServiceFin;

        return $this;
    }

    public function getCompteReport(): ?CompteComptable
    {
        return $this->compteReport;
    }

    public function setCompteReport(?CompteComptable $compteReport): self
    {
        $this->compteReport = $compteReport;

        return $this;
    }

    public function getMontantReporteCentimes(): int
    {
        return $this->montantReporteCentimes;
    }

    public function setMontantReporteCentimes(int $montantReporteCentimes): self
    {
        $this->montantReporteCentimes = $montantReporteCentimes;

        return $this;
    }

    public function getResteAServirCentimes(): int
    {
        return $this->resteAServirCentimes;
    }

    public function setResteAServirCentimes(int $resteAServirCentimes): self
    {
        $this->resteAServirCentimes = max(0, $resteAServirCentimes);

        return $this;
    }

    public function getNbUnitesCarte(): ?int
    {
        return $this->nbUnitesCarte;
    }

    public function setNbUnitesCarte(?int $nbUnitesCarte): self
    {
        $this->nbUnitesCarte = $nbUnitesCarte;

        return $this;
    }

    public function getIdentifiantSupport(): ?string
    {
        return $this->identifiantSupport;
    }

    public function setIdentifiantSupport(?string $identifiantSupport): self
    {
        $this->identifiantSupport = $identifiantSupport;

        return $this;
    }

    public function isSoldeResiduelTraite(): bool
    {
        return $this->soldeResiduelTraite;
    }

    public function setSoldeResiduelTraite(bool $soldeResiduelTraite): self
    {
        $this->soldeResiduelTraite = $soldeResiduelTraite;

        return $this;
    }
}
