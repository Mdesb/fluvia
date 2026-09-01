<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Finance\Treasury\Enum\CashAlertStatus;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Alerte de franchissement de seuil de trésorerie projeté (addendum FIN-4, RG-TRE-10 à RG-TRE-16) —
 * lecture seule côté API : la seule plume qui écrit cette entité est la commande planifiée
 * `finance:treasury:verifier-seuils` (§0.5 du plan) et, pour la résolution silencieuse à la
 * désactivation du seuil, `TreasurySettingsProcessor` (§0.6 du plan). Même philosophie que
 * `BankStatementLine.discrepancyNotifiedAt` : un fait produit par le système, jamais par un appelant
 * HTTP.
 *
 * `establishment` **direct** (même ancre que `BankAccount`/`TreasurySettings`, pas une chaîne de
 * jointure — l'alerte est un fait de premier niveau, §0.3 du plan).
 *
 * **Anti-répétition élevée au niveau base** (§0.3 du plan) : la colonne générée virtuelle
 * `open_establishment_id` (`NULL` hors `status = open`) porte l'index unique
 * `uniq_treasury_cash_alert_open_establishment` — au plus une alerte `open` par établissement, garanti
 * par MariaDB (qui exclut les `NULL` d'un index unique), pas seulement par un `findOneBy()` applicatif.
 * Cette colonne n'est **pas** mappée ici (elle n'a pas de sens applicatif PHP) : elle est ajoutée au
 * schéma par {@see \App\Finance\Treasury\Doctrine\TreasuryCashAlertSchemaListener}, pour que le schéma
 * de test (construit depuis les métadonnées ORM, `SchemaDuHarnais`) porte la même contrainte que la
 * migration `Version20260901090100` en production.
 *
 * `causeSource`/`causeSourceId`/`causeAmountCents` : référence polymorphe **sans FK** (comme
 * `PaymentScheduleCalculator::echeancier()['exits'][]['sourceId']` dont elle est la copie) — jamais un
 * pointeur Doctrine vers `SupplierInvoice`, cohérence délibérée avec l'existant (§0.3 du plan).
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_treasury_cash_alert')]
#[ORM\Index(columns: ['establishment_id'], name: 'idx_treasury_cash_alert_establishment')]
#[ORM\Index(columns: ['status'], name: 'idx_treasury_cash_alert_status')]
#[ApiResource(
    shortName: 'TreasuryCashAlert',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'finance.read')"),
        new Get(security: "is_granted('PERM', 'finance.read')"),
    ],
    normalizationContext: ['groups' => ['treasury_cash_alert:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact'])]
class TreasuryCashAlert
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['treasury_cash_alert:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    #[Groups(['treasury_cash_alert:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(length: 10, enumType: CashAlertStatus::class, options: ['default' => 'open'])]
    #[Groups(['treasury_cash_alert:read'])]
    private CashAlertStatus $status = CashAlertStatus::Open;

    /** Copié une seule fois, à la création — un seuil modifié après coup ne réécrit pas l'historique (§0.5 du plan). */
    #[ORM\Column(name: 'threshold_cents_at_detection', type: 'integer')]
    #[Groups(['treasury_cash_alert:read'])]
    private int $thresholdCentsAtDetection = 0;

    #[ORM\Column(name: 'horizon_days_at_detection', type: 'integer')]
    #[Groups(['treasury_cash_alert:read'])]
    private int $horizonDaysAtDetection = 0;

    /** Mis à jour à chaque passage (silencieux ou notifiant) tant que l'alerte reste `open`. */
    #[ORM\Column(name: 'projected_breach_date', type: 'date_immutable')]
    #[Groups(['treasury_cash_alert:read'])]
    private \DateTimeImmutable $projectedBreachDate;

    #[ORM\Column(name: 'projected_balance_cents', type: 'integer')]
    #[Groups(['treasury_cash_alert:read'])]
    private int $projectedBalanceCents = 0;

    /** RG-TRE-12 — `supplier_invoice` aujourd'hui, cohérent avec `PaymentScheduleCalculator::echeancier()`. */
    #[ORM\Column(name: 'cause_source', length: 32, nullable: true)]
    #[Groups(['treasury_cash_alert:read'])]
    private ?string $causeSource = null;

    #[ORM\Column(name: 'cause_source_id', type: UuidType::NAME, nullable: true)]
    #[Groups(['treasury_cash_alert:read'])]
    private ?Uuid $causeSourceId = null;

    #[ORM\Column(name: 'cause_amount_cents', type: 'integer', nullable: true)]
    #[Groups(['treasury_cash_alert:read'])]
    private ?int $causeAmountCents = null;

    /** Première détection de cette occurrence — immuable après création. */
    #[ORM\Column(name: 'detected_at', type: 'datetime_immutable')]
    #[Groups(['treasury_cash_alert:read'])]
    private \DateTimeImmutable $detectedAt;

    #[ORM\Column(name: 'last_checked_at', type: 'datetime_immutable')]
    #[Groups(['treasury_cash_alert:read'])]
    private \DateTimeImmutable $lastCheckedAt;

    /** Mis à jour seulement quand `treasury.threshold_breached` est effectivement émis (RG-TRE-13). */
    #[ORM\Column(name: 'last_notified_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['treasury_cash_alert:read'])]
    private ?\DateTimeImmutable $lastNotifiedAt = null;

    #[ORM\Column(name: 'resolved_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['treasury_cash_alert:read'])]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $now = new \DateTimeImmutable();
        $this->detectedAt = $now;
        $this->lastCheckedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEstablishment(): ?Etablissement
    {
        return $this->establishment;
    }

    public function setEstablishment(?Etablissement $establishment): self
    {
        $this->establishment = $establishment;

        return $this;
    }

    public function getStatus(): CashAlertStatus
    {
        return $this->status;
    }

    public function setStatus(CashAlertStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getThresholdCentsAtDetection(): int
    {
        return $this->thresholdCentsAtDetection;
    }

    public function setThresholdCentsAtDetection(int $thresholdCentsAtDetection): self
    {
        $this->thresholdCentsAtDetection = $thresholdCentsAtDetection;

        return $this;
    }

    public function getHorizonDaysAtDetection(): int
    {
        return $this->horizonDaysAtDetection;
    }

    public function setHorizonDaysAtDetection(int $horizonDaysAtDetection): self
    {
        $this->horizonDaysAtDetection = $horizonDaysAtDetection;

        return $this;
    }

    public function getProjectedBreachDate(): \DateTimeImmutable
    {
        return $this->projectedBreachDate;
    }

    public function setProjectedBreachDate(\DateTimeImmutable $projectedBreachDate): self
    {
        $this->projectedBreachDate = $projectedBreachDate;

        return $this;
    }

    public function getProjectedBalanceCents(): int
    {
        return $this->projectedBalanceCents;
    }

    public function setProjectedBalanceCents(int $projectedBalanceCents): self
    {
        $this->projectedBalanceCents = $projectedBalanceCents;

        return $this;
    }

    public function getCauseSource(): ?string
    {
        return $this->causeSource;
    }

    public function setCauseSource(?string $causeSource): self
    {
        $this->causeSource = $causeSource;

        return $this;
    }

    public function getCauseSourceId(): ?Uuid
    {
        return $this->causeSourceId;
    }

    public function setCauseSourceId(?Uuid $causeSourceId): self
    {
        $this->causeSourceId = $causeSourceId;

        return $this;
    }

    public function getCauseAmountCents(): ?int
    {
        return $this->causeAmountCents;
    }

    public function setCauseAmountCents(?int $causeAmountCents): self
    {
        $this->causeAmountCents = $causeAmountCents;

        return $this;
    }

    public function getDetectedAt(): \DateTimeImmutable
    {
        return $this->detectedAt;
    }

    public function getLastCheckedAt(): \DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function setLastCheckedAt(\DateTimeImmutable $lastCheckedAt): self
    {
        $this->lastCheckedAt = $lastCheckedAt;

        return $this;
    }

    public function getLastNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->lastNotifiedAt;
    }

    public function setLastNotifiedAt(?\DateTimeImmutable $lastNotifiedAt): self
    {
        $this->lastNotifiedAt = $lastNotifiedAt;

        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): self
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }
}
