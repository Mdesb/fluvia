<?php

declare(strict_types=1);

namespace App\Platform\Entity;

use App\Organisation\Entity\Etablissement;
use App\Platform\Nf525\SealAlertOrigin;
use App\Platform\Nf525\SealAlertStatus;
use App\Platform\Nf525\SealChainType;
use App\Platform\Nf525\SealRefusalCause;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Alerte « chaîne fiscale bloquée » : une ligne par chaîne scellée tant qu'elle est bloquée.
 *
 * ── PAS DE RESSOURCE API ────────────────────────────────────────────────────────────────────────
 *
 * Aucun `#[ApiResource]` : les deux API (établissement, éditeur) sont des DTO à fournisseur
 * explicite, pour qu'aucune sérialisation accidentelle ne rende `technicalDetail`.
 *
 * ── AUCUNE CLÉ ÉTRANGÈRE VERS LA CHAÎNE ─────────────────────────────────────────────────────────
 *
 * `pointOfSaleId`, `operatorProfileId`, `journalId` sont des identifiants simples : l'alerte s'écrit
 * sur une seconde connexion pendant que la transaction de l'appelant peut tenir des verrous sur ces
 * lignes ; une clé étrangère ferait attendre l'une l'autre (plan D-A).
 *
 * ── UNICITÉ DE L'ALERTE OUVERTE ─────────────────────────────────────────────────────────────────
 *
 * La colonne générée virtuelle `open_chain_key` (`chain_key` si `status = 'open'`, sinon `NULL`) et
 * son index UNIQUE `uniq_nf525_alert_open_chain` ne sont pas mappés ici : ils sont ajoutés au schéma
 * par {@see \App\Platform\Doctrine\Nf525AlertSchemaListener}. ⚠ NULL sous UNIQUE autorise les
 * doublons : `status` et `chain_key` sont donc NOT NULL, et l'enum n'écrit que `open`/`resolved`.
 *
 * Le cloisonnement est porté par la table de liaison `platform_nf525_alert_establishment`.
 *
 * La FK `establishment_id` → `org_etablissement` est RESTRICT (pas de ON DELETE), volontairement : NF525,
 * on ne purge pas ; un établissement lié à une alerte (même résolue) ne se supprime pas. Même comportement
 * que `finance_treasury_cash_alert`. Seule la FK côté alerte est en CASCADE.
 */
#[ORM\Entity]
#[ORM\Table(name: 'platform_nf525_alert')]
#[ORM\UniqueConstraint(name: 'uniq_nf525_alert_reference', columns: ['reference'])]
#[ORM\Index(columns: ['status', 'last_event_at'], name: 'idx_nf525_alert_status_last')]
class Nf525Alert
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Donnée au caissier : `NF525-` + 8 hexadécimaux majuscules. */
    #[ORM\Column(length: 16)]
    private string $reference;

    #[ORM\Column(name: 'chain_type', length: 16, enumType: SealChainType::class)]
    private SealChainType $chainType;

    /** `sales:<pdv>` · `accounting:<profil>:<journal>` · `invoicing:<profil>`. */
    #[ORM\Column(name: 'chain_key', length: 100)]
    private string $chainKey;

    /** Libellé public (« Caisse X »), jamais un identifiant. */
    #[ORM\Column(name: 'chain_label', length: 160)]
    private string $chainLabel;

    #[ORM\Column(name: 'point_of_sale_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $pointOfSaleId = null;

    #[ORM\Column(name: 'operator_profile_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $operatorProfileId = null;

    #[ORM\Column(name: 'journal_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $journalId = null;

    #[ORM\Column(length: 24, enumType: SealRefusalCause::class)]
    private SealRefusalCause $cause;

    #[ORM\Column(length: 16, enumType: SealAlertOrigin::class)]
    private SealAlertOrigin $origin;

    #[ORM\Column(length: 10, enumType: SealAlertStatus::class, options: ['default' => 'open'])]
    private SealAlertStatus $status = SealAlertStatus::Open;

    #[ORM\Column(name: 'refusal_count', type: 'integer', options: ['default' => 0])]
    private int $refusalCount = 0;

    #[ORM\Column(name: 'detection_count', type: 'integer', options: ['default' => 0])]
    private int $detectionCount = 0;

    #[ORM\Column(name: 'first_event_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $firstEventAt;

    #[ORM\Column(name: 'last_event_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastEventAt;

    /** Éditeur seulement : identifiants nettoyés, n° de maillon, jamais une clé (≤ 2000 caractères). */
    #[ORM\Column(name: 'technical_detail', type: 'text')]
    private string $technicalDetail = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'note_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $noteAt = null;

    #[ORM\Column(name: 'note_by', length: 180, nullable: true)]
    private ?string $noteBy = null;

    #[ORM\Column(name: 'resolved_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** @var Collection<int, Etablissement> */
    #[ORM\ManyToMany(targetEntity: Etablissement::class)]
    #[ORM\JoinTable(name: 'platform_nf525_alert_establishment')]
    #[ORM\JoinColumn(name: 'alert_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'establishment_id', referencedColumnName: 'id', nullable: false)]
    private Collection $establishments;

    public function __construct(
        string $reference,
        SealChainType $chainType,
        string $chainKey,
        string $chainLabel,
        SealRefusalCause $cause,
        SealAlertOrigin $origin,
    ) {
        $this->id = Uuid::v4();
        $this->reference = $reference;
        $this->chainType = $chainType;
        $this->chainKey = $chainKey;
        $this->chainLabel = $chainLabel;
        $this->cause = $cause;
        $this->origin = $origin;
        $now = new \DateTimeImmutable();
        $this->firstEventAt = $now;
        $this->lastEventAt = $now;
        $this->establishments = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getChainType(): SealChainType
    {
        return $this->chainType;
    }

    public function getChainKey(): string
    {
        return $this->chainKey;
    }

    public function getChainLabel(): string
    {
        return $this->chainLabel;
    }

    public function getPointOfSaleId(): ?Uuid
    {
        return $this->pointOfSaleId;
    }

    public function getOperatorProfileId(): ?Uuid
    {
        return $this->operatorProfileId;
    }

    public function getJournalId(): ?Uuid
    {
        return $this->journalId;
    }

    public function withChainIds(?Uuid $pointOfSaleId, ?Uuid $operatorProfileId, ?Uuid $journalId): self
    {
        $this->pointOfSaleId = $pointOfSaleId;
        $this->operatorProfileId = $operatorProfileId;
        $this->journalId = $journalId;

        return $this;
    }

    public function getCause(): SealRefusalCause
    {
        return $this->cause;
    }

    public function getOrigin(): SealAlertOrigin
    {
        return $this->origin;
    }

    public function getStatus(): SealAlertStatus
    {
        return $this->status;
    }

    public function getRefusalCount(): int
    {
        return $this->refusalCount;
    }

    public function getDetectionCount(): int
    {
        return $this->detectionCount;
    }

    public function getFirstEventAt(): \DateTimeImmutable
    {
        return $this->firstEventAt;
    }

    public function getLastEventAt(): \DateTimeImmutable
    {
        return $this->lastEventAt;
    }

    public function getTechnicalDetail(): string
    {
        return $this->technicalDetail;
    }

    public function withTechnicalDetail(string $technicalDetail): self
    {
        $this->technicalDetail = $technicalDetail;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getNoteAt(): ?\DateTimeImmutable
    {
        return $this->noteAt;
    }

    public function getNoteBy(): ?string
    {
        return $this->noteBy;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    /** Ferme l'alerte (G-7). */
    public function resolve(\DateTimeImmutable $at): self
    {
        $this->status = SealAlertStatus::Resolved;
        $this->resolvedAt = $at;

        return $this;
    }

    /** @return Collection<int, Etablissement> */
    public function getEstablishments(): Collection
    {
        return $this->establishments;
    }

    public function addEstablishment(Etablissement $establishment): self
    {
        if (!$this->establishments->contains($establishment)) {
            $this->establishments->add($establishment);
        }

        return $this;
    }
}
