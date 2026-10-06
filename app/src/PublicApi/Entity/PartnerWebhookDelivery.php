<?php

declare(strict_types=1);

namespace App\PublicApi\Entity;

use App\Organisation\Entity\Etablissement;
use App\PublicApi\Enum\DeliveryStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une livraison d'événement à UN abonnement, et son histoire : c'est la trace qui rend un échec
 * définitif visible côté éditeur, et l'état que le message de la file relit à chaque tentative.
 *
 * Le corps est figé à la création (l'enveloppe JSON exacte qui sera signée) : un réessai renvoie les
 * mêmes octets, sous le même identifiant d'idempotence. Il ne contient aucune donnée nominative.
 */
#[ORM\Entity]
#[ORM\Table(name: 'public_api_webhook_delivery')]
#[ORM\Index(columns: ['status', 'created_at'], name: 'idx_public_api_webhook_delivery_status')]
class PartnerWebhookDelivery
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PartnerWebhookSubscription::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PartnerWebhookSubscription $subscription;

    // CASCADE : un établissement supprimé emporte les livraisons de ses événements.
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Etablissement $etablissement;

    /** L'identifiant de l'ÉVÉNEMENT, commun à tous ses abonnés : c'est la clé d'idempotence. */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $eventId;

    #[ORM\Column(length: 64)]
    private string $eventType;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(length: 12, enumType: DeliveryStatus::class, options: ['default' => 'pending'])]
    private DeliveryStatus $status = DeliveryStatus::Pending;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(PartnerWebhookSubscription $subscription, Etablissement $etablissement, Uuid $eventId, string $eventType, string $body)
    {
        $this->id = Uuid::v7();
        $this->subscription = $subscription;
        $this->etablissement = $etablissement;
        $this->eventId = $eventId;
        $this->eventType = $eventType;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSubscription(): PartnerWebhookSubscription
    {
        return $this->subscription;
    }

    public function getEtablissement(): Etablissement
    {
        return $this->etablissement;
    }

    public function getEventId(): Uuid
    {
        return $this->eventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getStatus(): DeliveryStatus
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Une tentative de plus, et ce qu'elle a donné. */
    public function recordAttempt(DeliveryStatus $status, ?string $error): void
    {
        ++$this->attempts;
        $this->status = $status;
        $this->lastError = null === $error ? null : mb_substr($error, 0, 255);
        $this->lastAttemptAt = new \DateTimeImmutable();
    }

    /** Fermée sans tentative (accord retiré, abonnement coupé) : la raison reste lisible. */
    public function abandon(string $reason): void
    {
        $this->status = DeliveryStatus::Abandoned;
        $this->lastError = mb_substr($reason, 0, 255);
        $this->lastAttemptAt = new \DateTimeImmutable();
    }
}
