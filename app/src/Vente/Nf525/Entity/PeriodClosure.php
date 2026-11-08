<?php

declare(strict_types=1);

namespace App\Vente\Nf525\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Enum\TypeCloture;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Clôture mensuelle ou annuelle d'un point de vente (#342) — un arrêté de totaux **cumulés**, scellé
 * dans la chaîne comme l'arrêté de journée dont il part.
 *
 * **Pourquoi elle existe.** BOI-TVA-DECLA-30-10-30 § 170 (version du 25/03/2026) : les clôtures
 * journalière, mensuelle et annuelle (ou par exercice) sont « cumulatives et impératives ». Le dépôt
 * avait la première (`DailyClosure`, D57) ; `TypeCloture` déclarait les deux autres sans qu'aucun code
 * ne les produise.
 *
 * **Elle ne recompte pas les ventes : elle additionne des arrêtés de journée.** Une clôture du mois qui
 * relirait `vente_vente` affirmerait ce que dit la table aujourd'hui ; celle-ci affirme ce que les
 * journées ont scellé. Si les deux divergent, c'est qu'une vente a bougé après son arrêté — et
 * `recomputedTotal` est là pour que cet écart se lise au lieu d'être absorbé.
 *
 * **Les trois totaux du § 170.**
 * - `periodTotal` : la période elle-même (le mois, ou l'année) ;
 * - `fiscalYearTotal` : le grand total de la période, cumulé depuis le début de l'exercice ;
 * - `grandTotal` : le total perpétuel, jamais remis à zéro, repris de la dernière journée arrêtée.
 *
 * Immuable comme tout ce qui est scellé : aucune écriture exposée, `InalterabiliteListener` refuse la
 * modification et la suppression.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nf525_period_closure')]
// Une seule clôture par période et par point de vente : deux clôtures du même mois compteraient deux
// fois ce mois dans le cumul d'exercice. Le contrôle est aussi en code, mais seul l'index survit à deux
// requêtes concurrentes.
#[ORM\UniqueConstraint(name: 'uniq_period_closure_pdv_type_start', columns: ['point_de_vente_id', 'closure_type', 'period_start'])]
#[ApiResource(
    shortName: 'PeriodClosure',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'vente.lire')", order: ['periodStart' => 'DESC']),
        new Get(security: "is_granted('PERM', 'vente.lire')"),
    ],
    normalizationContext: ['groups' => ['period_closure:read']],
)]
class PeriodClosure
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['period_closure:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PointDeVente::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['period_closure:read'])]
    private ?PointDeVente $pointDeVente = null;

    #[ORM\Column(name: 'closure_type', length: 12, enumType: TypeCloture::class)]
    #[Groups(['period_closure:read'])]
    private TypeCloture $type = TypeCloture::Mensuelle;

    /** Premier jour de la période (le 1er du mois, ou le 1er janvier). */
    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['period_closure:read'])]
    private \DateTimeImmutable $periodStart;

    /** Dernier jour de la période, inclus. */
    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['period_closure:read'])]
    private \DateTimeImmutable $periodEnd;

    /** Nombre d'arrêtés de journée que la période additionne. */
    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['period_closure:read'])]
    private int $dailyClosureCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['period_closure:read'])]
    private int $salesCount = 0;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['period_closure:read'])]
    private string $periodTotal = '0.00';

    /** Cumul depuis le début de l'exercice (année civile), période comprise. */
    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['period_closure:read'])]
    private string $fiscalYearTotal = '0.00';

    /** Total perpétuel à l'ouverture de la période (celui de la dernière journée arrêtée avant elle). */
    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['period_closure:read'])]
    private string $previousGrandTotal = '0.00';

    /** Total perpétuel à la fin de la période. */
    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['period_closure:read'])]
    private string $grandTotal = '0.00';

    /**
     * Le total des ventes scellées de la période, **relu dans la table des ventes** au moment de la
     * clôture. Il n'entre dans aucun cumul : c'est un témoin. Égal à `periodTotal` sur une base
     * intacte ; différent, il dit qu'une vente a bougé après l'arrêté de sa journée.
     */
    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['period_closure:read'])]
    private string $recomputedTotal = '0.00';

    #[ORM\Column(type: 'bigint', nullable: true)]
    #[Groups(['period_closure:read'])]
    private ?int $lastSequence = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['period_closure:read'])]
    private \DateTimeImmutable $closedAt;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['period_closure:read'])]
    private ?Utilisateur $author = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->periodStart = new \DateTimeImmutable('first day of this month midnight');
        $this->periodEnd = new \DateTimeImmutable('last day of this month midnight');
        $this->closedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPointDeVente(): ?PointDeVente
    {
        return $this->pointDeVente;
    }

    public function setPointDeVente(?PointDeVente $pointDeVente): self
    {
        $this->pointDeVente = $pointDeVente;

        return $this;
    }

    public function getType(): TypeCloture
    {
        return $this->type;
    }

    public function setType(TypeCloture $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function setPeriodStart(\DateTimeImmutable $periodStart): self
    {
        $this->periodStart = $periodStart;

        return $this;
    }

    public function getPeriodEnd(): \DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function setPeriodEnd(\DateTimeImmutable $periodEnd): self
    {
        $this->periodEnd = $periodEnd;

        return $this;
    }

    public function getDailyClosureCount(): int
    {
        return $this->dailyClosureCount;
    }

    public function setDailyClosureCount(int $dailyClosureCount): self
    {
        $this->dailyClosureCount = $dailyClosureCount;

        return $this;
    }

    public function getSalesCount(): int
    {
        return $this->salesCount;
    }

    public function setSalesCount(int $salesCount): self
    {
        $this->salesCount = $salesCount;

        return $this;
    }

    public function getPeriodTotal(): string
    {
        return $this->periodTotal;
    }

    public function setPeriodTotal(string $periodTotal): self
    {
        $this->periodTotal = $periodTotal;

        return $this;
    }

    public function getFiscalYearTotal(): string
    {
        return $this->fiscalYearTotal;
    }

    public function setFiscalYearTotal(string $fiscalYearTotal): self
    {
        $this->fiscalYearTotal = $fiscalYearTotal;

        return $this;
    }

    public function getPreviousGrandTotal(): string
    {
        return $this->previousGrandTotal;
    }

    public function setPreviousGrandTotal(string $previousGrandTotal): self
    {
        $this->previousGrandTotal = $previousGrandTotal;

        return $this;
    }

    public function getGrandTotal(): string
    {
        return $this->grandTotal;
    }

    public function setGrandTotal(string $grandTotal): self
    {
        $this->grandTotal = $grandTotal;

        return $this;
    }

    public function getRecomputedTotal(): string
    {
        return $this->recomputedTotal;
    }

    public function setRecomputedTotal(string $recomputedTotal): self
    {
        $this->recomputedTotal = $recomputedTotal;

        return $this;
    }

    /** Vrai quand la table des ventes ne dit plus ce que les journées ont scellé. */
    #[Groups(['period_closure:read'])]
    public function hasDiscrepancy(): bool
    {
        return $this->recomputedTotal !== $this->periodTotal;
    }

    public function getLastSequence(): ?int
    {
        return $this->lastSequence;
    }

    public function setLastSequence(?int $lastSequence): self
    {
        $this->lastSequence = $lastSequence;

        return $this;
    }

    public function getClosedAt(): \DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getAuthor(): ?Utilisateur
    {
        return $this->author;
    }

    public function setAuthor(?Utilisateur $author): self
    {
        $this->author = $author;

        return $this;
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
}
