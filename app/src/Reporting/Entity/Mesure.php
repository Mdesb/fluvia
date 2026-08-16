<?php

declare(strict_types=1);

namespace App\Reporting\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Reporting\Entity\Trait\RattachementNiveauInterface;
use App\Reporting\Entity\Trait\RattachementNiveauTrait;
use App\Reporting\Enum\GranulariteMesure;
use App\Reporting\Enum\RegimeExploitantMesure;
use App\Reporting\Enum\StatutCompletude;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Table de faits pré-agrégée — cœur de M7 (§1.4 plan-reporting.md). Écrite UNIQUEMENT par
 * `reporting:agreger` (`AgregateurMesuresService`), jamais via l'API (Get/GetCollection seuls,
 * patron « append-only » plus strict que `Passage` : zéro écriture API du tout). Filtrée par
 * `PerimetreReportingExtension` (RG-M7-01).
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_mesure')]
#[ORM\UniqueConstraint(name: 'uniq_mesure_cle_agregation', columns: ['cle_agregation'])]
#[ORM\Index(columns: ['indicateur_id', 'niveau', 'etablissement_id', 'periode_debut', 'periode_fin', 'granularite'], name: 'idx_mesure_etablissement')]
#[ORM\Index(columns: ['indicateur_id', 'niveau', 'region_id', 'periode_debut', 'periode_fin', 'granularite'], name: 'idx_mesure_region')]
#[ORM\Index(columns: ['indicateur_id', 'niveau', 'groupe_id', 'periode_debut', 'periode_fin', 'granularite'], name: 'idx_mesure_groupe')]
#[ApiResource(
    shortName: 'Mesure',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reporting.lire')"),
        new Get(security: "is_granted('PERM', 'reporting.lire')"),
    ],
    normalizationContext: ['groups' => ['mesure:read']],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'indicateur' => 'exact', 'niveau' => 'exact', 'etablissement' => 'exact', 'region' => 'exact', 'groupe' => 'exact',
    'activite' => 'exact', 'canal' => 'exact', 'granularite' => 'exact', 'statutCompletude' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['periodeDebut', 'periodeFin'])]
class Mesure implements RattachementNiveauInterface
{
    use RattachementNiveauTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['mesure:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Indicateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mesure:read'])]
    private ?Indicateur $indicateur = null;

    /** Code verticale — référence logique, pas de référentiel « Activité » unifié dans le socle (Risque §9.3). */
    #[ORM\Column(length: 60, nullable: true)]
    #[Groups(['mesure:read'])]
    private ?string $activite = null;

    /** Réf. logique `Offre\Entity\Produit` — pas de FK dure (même patron que `Vente.client`). */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['mesure:read'])]
    private ?Uuid $produit = null;

    /** Réf. logique `Offre\Entity\Categorie`, idem. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['mesure:read'])]
    private ?Uuid $categorie = null;

    /** Liste blanche applicative (Risque §9.4) : valeurs `Offre\Enum\Canal` ou `dsp`/`regie`. */
    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['mesure:read'])]
    private ?string $canal = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['mesure:read'])]
    private \DateTimeImmutable $periodeDebut;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['mesure:read'])]
    private \DateTimeImmutable $periodeFin;

    #[ORM\Column(length: 8, enumType: GranulariteMesure::class)]
    #[Groups(['mesure:read'])]
    private GranulariteMesure $granularite = GranulariteMesure::Jour;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    #[Groups(['mesure:read'])]
    private string $valeur = '0.00';

    #[ORM\Column(length: 14, enumType: RegimeExploitantMesure::class, nullable: true)]
    #[Groups(['mesure:read'])]
    private ?RegimeExploitantMesure $regimeExploitant = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['mesure:read'])]
    private bool $comparabiliteRegime = false;

    #[ORM\Column(length: 8, enumType: StatutCompletude::class, options: ['default' => 'complet'])]
    #[Groups(['mesure:read'])]
    private StatutCompletude $statutCompletude = StatutCompletude::Complet;

    /** @var list<string>|null Uuids d'établissements manquants (traçabilité, RG-REPORT-11). */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['mesure:read'])]
    private ?array $sitesManquants = null;

    #[ORM\Column(length: 40, options: ['default' => 'Europe/Paris'])]
    #[Groups(['mesure:read'])]
    private string $fuseauReference = 'Europe/Paris';

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    #[Groups(['mesure:read'])]
    private string $devise = 'EUR';

    /** Hash déterministe du tuple de dimensions — clé d'upsert idempotent (§2.1 plan-reporting.md). */
    #[ORM\Column(length: 64, unique: true)]
    #[Groups(['mesure:read'])]
    private string $cleAgregation = '';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['mesure:read'])]
    private \DateTimeImmutable $genereLe;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->periodeDebut = new \DateTimeImmutable('today');
        $this->periodeFin = new \DateTimeImmutable('today');
        $this->genereLe = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIndicateur(): ?Indicateur
    {
        return $this->indicateur;
    }

    public function setIndicateur(?Indicateur $indicateur): self
    {
        $this->indicateur = $indicateur;

        return $this;
    }

    public function getActivite(): ?string
    {
        return $this->activite;
    }

    public function setActivite(?string $activite): self
    {
        $this->activite = $activite;

        return $this;
    }

    public function getProduit(): ?Uuid
    {
        return $this->produit;
    }

    public function setProduit(?Uuid $produit): self
    {
        $this->produit = $produit;

        return $this;
    }

    public function getCategorie(): ?Uuid
    {
        return $this->categorie;
    }

    public function setCategorie(?Uuid $categorie): self
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getCanal(): ?string
    {
        return $this->canal;
    }

    public function setCanal(?string $canal): self
    {
        $this->canal = $canal;

        return $this;
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

    public function getGranularite(): GranulariteMesure
    {
        return $this->granularite;
    }

    public function setGranularite(GranulariteMesure $granularite): self
    {
        $this->granularite = $granularite;

        return $this;
    }

    public function getValeur(): string
    {
        return $this->valeur;
    }

    public function setValeur(string $valeur): self
    {
        $this->valeur = $valeur;

        return $this;
    }

    public function getRegimeExploitant(): ?RegimeExploitantMesure
    {
        return $this->regimeExploitant;
    }

    public function setRegimeExploitant(?RegimeExploitantMesure $regimeExploitant): self
    {
        $this->regimeExploitant = $regimeExploitant;

        return $this;
    }

    public function isComparabiliteRegime(): bool
    {
        return $this->comparabiliteRegime;
    }

    public function setComparabiliteRegime(bool $comparabiliteRegime): self
    {
        $this->comparabiliteRegime = $comparabiliteRegime;

        return $this;
    }

    public function getStatutCompletude(): StatutCompletude
    {
        return $this->statutCompletude;
    }

    public function setStatutCompletude(StatutCompletude $statutCompletude): self
    {
        $this->statutCompletude = $statutCompletude;

        return $this;
    }

    /** @return list<string>|null */
    public function getSitesManquants(): ?array
    {
        return $this->sitesManquants;
    }

    /** @param list<string>|null $sitesManquants */
    public function setSitesManquants(?array $sitesManquants): self
    {
        $this->sitesManquants = $sitesManquants;

        return $this;
    }

    public function getFuseauReference(): string
    {
        return $this->fuseauReference;
    }

    public function setFuseauReference(string $fuseauReference): self
    {
        $this->fuseauReference = $fuseauReference;

        return $this;
    }

    public function getDevise(): string
    {
        return $this->devise;
    }

    public function setDevise(string $devise): self
    {
        $this->devise = $devise;

        return $this;
    }

    public function getCleAgregation(): string
    {
        return $this->cleAgregation;
    }

    public function setCleAgregation(string $cleAgregation): self
    {
        $this->cleAgregation = $cleAgregation;

        return $this;
    }

    public function getGenereLe(): \DateTimeImmutable
    {
        return $this->genereLe;
    }

    public function setGenereLe(\DateTimeImmutable $genereLe): self
    {
        $this->genereLe = $genereLe;

        return $this;
    }

    /**
     * Hash déterministe du tuple de dimensions (§2.1 plan-reporting.md) — clé d'upsert idempotent
     * de `reporting:agreger` : recalculer une période déjà agrégée met à jour la ligne, ne duplique
     * jamais.
     */
    public static function calculerCleAgregation(
        string $indicateurCode,
        string $niveau,
        string $entiteId,
        ?string $activite,
        ?string $produit,
        ?string $categorie,
        ?string $canal,
        string $periodeDebut,
        string $periodeFin,
        string $granularite,
    ): string {
        return hash('sha256', implode('|', [
            $indicateurCode, $niveau, $entiteId, $activite ?? '', $produit ?? '', $categorie ?? '', $canal ?? '',
            $periodeDebut, $periodeFin, $granularite,
        ]));
    }
}
