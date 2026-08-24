<?php

declare(strict_types=1);

namespace App\Reservation\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Enum\IssueCreditNoShow;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Reservation\Enum\ModeMontantAnnulation;
use App\Reservation\Enum\PorteeRegleAnnulation;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Règle de no-show / annulation tardive (RG-M5-09). Portée à plusieurs niveaux (établissement, type
 * de ressource, ressource, activité) — la plus spécifique l'emporte (décision structurante n°2 du
 * plan, `ResolveurRegleAnnulation`, ⚠ à confirmer produit).
 */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_regle_annulation')]
#[ApiResource(
    shortName: 'ReservationRegleAnnulation',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reservation.lire')"),
        new Get(security: "is_granted('PERM', 'reservation.lire')"),
        new Post(security: "is_granted('PERM', 'reservation.parametrer_annulation')"),
        new Patch(security: "is_granted('PERM', 'reservation.parametrer_annulation')"),
    ],
    normalizationContext: ['groups' => ['regle_annulation:read']],
    denormalizationContext: ['groups' => ['regle_annulation:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['portee' => 'exact', 'actif' => 'exact'])]
class RegleAnnulation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['regle_annulation:read', 'facturation_no_show:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['regle_annulation:read', 'regle_annulation:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 14, enumType: PorteeRegleAnnulation::class)]
    #[Groups(['regle_annulation:read', 'regle_annulation:write'])]
    private PorteeRegleAnnulation $portee = PorteeRegleAnnulation::Etablissement;

    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['regle_annulation:read', 'regle_annulation:write'])]
    private ?string $cibleTypeRessource = null;

    #[ORM\ManyToOne(targetEntity: Ressource::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['regle_annulation:read', 'regle_annulation:write'])]
    private ?Ressource $cibleRessource = null;

    #[ORM\ManyToOne(targetEntity: Activite::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['regle_annulation:read', 'regle_annulation:write'])]
    private ?Activite $cibleActivite = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero]
    #[Groups(['regle_annulation:read', 'regle_annulation:write'])]
    private int $delaiFrancMinutes = 1440;

    #[ORM\Column(length: 11, enumType: ModeMontantAnnulation::class)]
    #[Groups(['regle_annulation:read', 'regle_annulation:write'])]
    private ModeMontantAnnulation $modeMontant = ModeMontantAnnulation::Fixe;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['regle_annulation:read', 'regle_annulation:write'])]
    private string $valeurMontant = '0.00';

    /** @var list<array{motif: string, condition: string}>|null */
    #[ORM\Column(nullable: true)]
    #[Groups(['regle_annulation:read', 'regle_annulation:write'])]
    private ?array $exonerations = null;

    #[ORM\Column(length: 20, enumType: ModeFacturationNoShow::class)]
    #[Groups(['regle_annulation:read', 'regle_annulation:write', 'facturation_no_show:read'])]
    private ModeFacturationNoShow $modeFacturation = ModeFacturationNoShow::FactureAEncaisser;

    /**
     * Second axe orthogonal (D24, RG-CQ5-01) : la séance manquée est-elle décomptée du crédit,
     * restituée, ou restituée avec un report proposé ? Défaut le plus généreux (D27), même rang que
     * `modeFacturation`. `facturation_no_show:read` permet à `FacturationNoShow.regleAppliquee` de
     * projeter ce que dirait la règle aujourd'hui, distinctement de ce qui a réellement été décidé
     * (`FacturationNoShow.issueCreditNoShow`, RG-CQ5-06).
     */
    #[ORM\Column(length: 24, enumType: IssueCreditNoShow::class)]
    #[Groups(['regle_annulation:read', 'regle_annulation:write', 'facturation_no_show:read'])]
    private IssueCreditNoShow $issueCreditNoShow = IssueCreditNoShow::RestoredWithReschedule;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['regle_annulation:read', 'regle_annulation:write'])]
    private int $margePostCreneauMinutes = 0;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['regle_annulation:read', 'regle_annulation:write'])]
    private bool $actif = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    public function getPortee(): PorteeRegleAnnulation
    {
        return $this->portee;
    }

    public function setPortee(PorteeRegleAnnulation $portee): self
    {
        $this->portee = $portee;

        return $this;
    }

    public function getCibleTypeRessource(): ?string
    {
        return $this->cibleTypeRessource;
    }

    public function setCibleTypeRessource(?string $cibleTypeRessource): self
    {
        $this->cibleTypeRessource = $cibleTypeRessource;

        return $this;
    }

    public function getCibleRessource(): ?Ressource
    {
        return $this->cibleRessource;
    }

    public function setCibleRessource(?Ressource $cibleRessource): self
    {
        $this->cibleRessource = $cibleRessource;

        return $this;
    }

    public function getCibleActivite(): ?Activite
    {
        return $this->cibleActivite;
    }

    public function setCibleActivite(?Activite $cibleActivite): self
    {
        $this->cibleActivite = $cibleActivite;

        return $this;
    }

    public function getDelaiFrancMinutes(): int
    {
        return $this->delaiFrancMinutes;
    }

    public function setDelaiFrancMinutes(int $delaiFrancMinutes): self
    {
        $this->delaiFrancMinutes = $delaiFrancMinutes;

        return $this;
    }

    public function getModeMontant(): ModeMontantAnnulation
    {
        return $this->modeMontant;
    }

    public function setModeMontant(ModeMontantAnnulation $modeMontant): self
    {
        $this->modeMontant = $modeMontant;

        return $this;
    }

    public function getValeurMontant(): string
    {
        return $this->valeurMontant;
    }

    public function setValeurMontant(string $valeurMontant): self
    {
        $this->valeurMontant = $valeurMontant;

        return $this;
    }

    /** @return list<array{motif: string, condition: string}> */
    public function getExonerations(): array
    {
        return $this->exonerations ?? [];
    }

    /** @param list<array{motif: string, condition: string}>|null $exonerations */
    public function setExonerations(?array $exonerations): self
    {
        $this->exonerations = $exonerations;

        return $this;
    }

    public function getModeFacturation(): ModeFacturationNoShow
    {
        return $this->modeFacturation;
    }

    public function setModeFacturation(ModeFacturationNoShow $modeFacturation): self
    {
        $this->modeFacturation = $modeFacturation;

        return $this;
    }

    public function getIssueCreditNoShow(): IssueCreditNoShow
    {
        return $this->issueCreditNoShow;
    }

    public function setIssueCreditNoShow(IssueCreditNoShow $issueCreditNoShow): self
    {
        $this->issueCreditNoShow = $issueCreditNoShow;

        return $this;
    }

    public function getMargePostCreneauMinutes(): int
    {
        return $this->margePostCreneauMinutes;
    }

    public function setMargePostCreneauMinutes(int $margePostCreneauMinutes): self
    {
        $this->margePostCreneauMinutes = $margePostCreneauMinutes;

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

    /** Montant calculé (fixe ou % du tarif de référence), en decimal(10,2). */
    public function montantCalcule(string $tarifReference): string
    {
        if ($this->modeMontant === ModeMontantAnnulation::Fixe) {
            return number_format((float) $this->valeurMontant, 2, '.', '');
        }

        $centimes = (int) round(((float) $tarifReference) * 100 * ((float) $this->valeurMontant) / 100);

        return number_format($centimes / 100, 2, '.', '');
    }
}
