<?php

declare(strict_types=1);

namespace App\Kiosk\Entity;

use App\Vente\Entity\Vente;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une commande passée à une borne : la vente qu'elle a ouverte, et ce que la vente ne sait pas dire.
 *
 * - QUELLE borne : une borne ne touche qu'aux ventes qu'ELLE a ouvertes. Le contrôle passe par cette
 *   ligne, pas par le point de vente : deux bornes ne partagent jamais un point de vente (index unique),
 *   mais la ligne rend le contrôle explicite et indépendant de cette règle.
 * - la CARTE À RECHARGER, lue à l'ouverture et vérifiée AVANT le paiement : l'usager ne paie jamais une
 *   recharge sur une carte inconnue. Elle est relue à la validation, jamais reprise du corps.
 * - la REMISE : quand la vente a été validée et le titre remis (ticket, ou badge et son issue).
 *
 * Pas une ressource d'API : l'exploitant lit ces ventes dans la caisse, par leur point de vente.
 */
#[ORM\Entity]
#[ORM\Table(name: 'kiosk_order')]
#[ORM\UniqueConstraint(name: 'uniq_kiosk_order_sale', columns: ['sale_id'])]
class KioskOrder
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Kiosk::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Kiosk $kiosk;

    #[ORM\ManyToOne(targetEntity: Vente::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Vente $sale;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $rechargeCardCode = null;

    /** La ligne de vente qui porte la recharge (son identifiant sert à `ValiderVenteService`). */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $rechargeLineId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $abandonedAt = null;

    /** issued | unavailable | failed — nul tant que rien n'a été demandé à l'encodeur. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $badgeStatus = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $badgeMessage = null;

    public function __construct(Kiosk $kiosk, Vente $sale)
    {
        $this->id = Uuid::v4();
        $this->kiosk = $kiosk;
        $this->sale = $sale;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKiosk(): Kiosk
    {
        return $this->kiosk;
    }

    public function getSale(): Vente
    {
        return $this->sale;
    }

    public function getRechargeCardCode(): ?string
    {
        return $this->rechargeCardCode;
    }

    public function getRechargeLineId(): ?Uuid
    {
        return $this->rechargeLineId;
    }

    public function setRecharge(string $cardCode, Uuid $lineId): self
    {
        $this->rechargeCardCode = $cardCode;
        $this->rechargeLineId = $lineId;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function markCompleted(\DateTimeImmutable $at): self
    {
        $this->completedAt = $at;

        return $this;
    }

    public function getAbandonedAt(): ?\DateTimeImmutable
    {
        return $this->abandonedAt;
    }

    public function markAbandoned(\DateTimeImmutable $at): self
    {
        $this->abandonedAt = $at;

        return $this;
    }

    public function getBadgeStatus(): ?string
    {
        return $this->badgeStatus;
    }

    public function getBadgeMessage(): ?string
    {
        return $this->badgeMessage;
    }

    public function setBadgeOutcome(string $status, ?string $message): self
    {
        $this->badgeStatus = $status;
        $this->badgeMessage = $message !== null ? mb_substr($message, 0, 255) : null;

        return $this;
    }
}
