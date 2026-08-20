<?php

declare(strict_types=1);

namespace App\Subscription\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une option souscrite, avec ses dates d'effet (ED-2).
 *
 * **Pourquoi des dates plutôt qu'un simple booléen.** Parce qu'une option ajoutée le 16 et une option
 * présente depuis le 1er ne se facturent pas pareil, et parce qu'une option retirée reste due jusqu'à
 * la fin de la période déjà payée. Un drapeau `active` perdrait les deux informations, et il faudrait
 * les reconstituer depuis les factures — c'est-à-dire trop tard.
 *
 * `unitPriceCents` fige le prix **au moment de la souscription** : une hausse du catalogue ne
 * s'applique pas rétroactivement à qui a déjà signé.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscription_item')]
class SubscriptionItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Subscription::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Subscription $subscription = null;

    /** Code de capacité du registre — la même donnée que celle qu'on active (RG-ED-03). */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    private string $capability = '';

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $unitPriceCents = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $activeFrom;

    /** `null` tant que l'option court. Renseigné au retrait, à la fin de la période déjà payée. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $activeTo = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->activeFrom = new \DateTimeImmutable();
    }

    /** L'option court-elle à cet instant ? */
    public function isActiveAt(\DateTimeImmutable $instant): bool
    {
        if ($instant < $this->activeFrom) {
            return false;
        }

        return null === $this->activeTo || $instant < $this->activeTo;
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

    public function getCapability(): string
    {
        return $this->capability;
    }

    public function setCapability(string $capability): self
    {
        $this->capability = $capability;

        return $this;
    }

    public function getUnitPriceCents(): int
    {
        return $this->unitPriceCents;
    }

    public function setUnitPriceCents(int $unitPriceCents): self
    {
        $this->unitPriceCents = $unitPriceCents;

        return $this;
    }

    public function getActiveFrom(): \DateTimeImmutable
    {
        return $this->activeFrom;
    }

    public function setActiveFrom(\DateTimeImmutable $activeFrom): self
    {
        $this->activeFrom = $activeFrom;

        return $this;
    }

    public function getActiveTo(): ?\DateTimeImmutable
    {
        return $this->activeTo;
    }

    public function setActiveTo(?\DateTimeImmutable $activeTo): self
    {
        $this->activeTo = $activeTo;

        return $this;
    }
}
