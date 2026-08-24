<?php

declare(strict_types=1);

namespace App\Subscription\Entity;

use App\Organisation\Entity\Etablissement;
use App\Subscription\Enum\ProvisioningStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * La trace d'un provisioning, et le verrou qui le rend idempotent (ED-3, RG-ED-05).
 *
 * **L'idempotence est portée par une contrainte d'unicité, pas par une lecture préalable.** Un
 * webhook bancaire se répète, et deux répétitions peuvent arriver assez près l'une de l'autre pour
 * que les deux traitements lisent « aucune demande existante » avant que l'un des deux n'écrive. Le
 * `SELECT` suivi d'un `INSERT` ne protège de rien dans ce cas : seule la base peut arbitrer. C'est
 * `subscription_id` en unique qui garantit qu'il n'existera jamais deux établissements pour un même
 * abonnement — le second `INSERT` échoue, et l'appelant récupère la demande gagnante.
 *
 * **`attempts` compte les rejeux, il ne les autorise pas.** Il sert à voir, en exploitation, qu'un
 * événement a été reçu quatre fois ; il ne conditionne aucune décision. Une logique qui rejouerait
 * « tant que attempts < 3 » réintroduirait exactement le double provisioning qu'on ferme ici.
 *
 * **`establishment` reste nul en cas d'échec**, et c'est ce qui distingue une demande ratée d'une
 * demande réussie : on ne déduit jamais le succès du statut seul.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscription_provisioning_request')]
#[ORM\UniqueConstraint(name: 'uniq_provisioning_subscription', columns: ['subscription_id'])]
class ProvisioningRequest
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /**
     * L'abonnement provisionné — la clé métier stable de l'idempotence.
     *
     * Ce n'est volontairement ni l'e-mail de l'administrateur, ni la référence client : deux
     * abonnements successifs du même client sont deux provisionings légitimes, alors que deux
     * réceptions du même `subscription.activated` n'en sont qu'un.
     */
    #[ORM\OneToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?Subscription $subscription = null;

    /** L'établissement créé. Nul tant que le provisioning n'a pas abouti. */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Etablissement $establishment = null;

    #[ORM\Column(length: 16, enumType: ProvisioningStatus::class)]
    private ProvisioningStatus $status = ProvisioningStatus::Pending;

    /** Nombre de fois où le provisioning a été demandé pour cet abonnement. Observation seule. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $attempts = 0;

    /**
     * Cause de l'échec, en clair, destinée à l'exploitant.
     *
     * Un provisioning qui échoue laisse un client payant sans plateforme : la cause doit être
     * lisible sans ouvrir les journaux applicatifs.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSubscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function setSubscription(?Subscription $subscription): self
    {
        $this->subscription = $subscription;

        return $this;
    }

    public function getEstablishment(): ?Etablissement
    {
        return $this->establishment;
    }

    public function getStatus(): ProvisioningStatus
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    /** Enregistre une demande supplémentaire pour cet abonnement (observation, RG-ED-05). */
    public function recordAttempt(): self
    {
        ++$this->attempts;

        return $this;
    }

    /**
     * Clôt la demande sur un succès : l'établissement existe, son administrateur aussi.
     *
     * Le succès et l'établissement sont posés ensemble, en une seule méthode, pour qu'aucun appelant
     * ne puisse produire une demande `Completed` sans établissement — un état qui ferait croire à un
     * client provisionné qui ne l'est pas.
     */
    public function complete(Etablissement $establishment): self
    {
        $this->establishment = $establishment;
        $this->status = ProvisioningStatus::Completed;
        $this->failureReason = null;
        $this->completedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Clôt la demande sur un échec qu'un rejeu à l'identique ne lèverait pas. */
    public function fail(string $reason): self
    {
        $this->status = ProvisioningStatus::Failed;
        $this->failureReason = $reason;

        return $this;
    }
}
