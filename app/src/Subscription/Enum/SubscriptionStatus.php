<?php

declare(strict_types=1);

namespace App\Subscription\Enum;

/**
 * États d'un abonnement, et les seuls passages autorisés entre eux (ED-2).
 *
 * Les transitions vivent ici plutôt que dispersées dans des services : c'est le seul endroit où l'on
 * peut lire, d'un coup d'œil, qu'un abonnement résilié ne redevient jamais actif — et le seul endroit
 * à corriger le jour où cette règle change.
 */
enum SubscriptionStatus: string
{
    /** Composé, pas encore payé. Aucun module n'est exposé. */
    case Draft = 'draft';

    /** Payé et en cours. Les modules souscrits sont exposés. */
    case Active = 'active';

    /** Impayé : l'exposition est coupée, **aucune donnée n'est supprimée** (RG-ED-06). */
    case Suspended = 'suspended';

    /** Terminé. État final : un abonnement résilié ne se réactive pas, on en souscrit un nouveau. */
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Cancelled],
            self::Active => [self::Suspended, self::Cancelled],
            // Régularisation : c'est tout l'intérêt de suspendre plutôt que de résilier.
            self::Suspended => [self::Active, self::Cancelled],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $cible): bool
    {
        return \in_array($cible, $this->allowedTransitions(), true);
    }

    /** L'abonnement donne-t-il droit aux modules souscrits, à cet instant ? */
    public function grantsAccess(): bool
    {
        return self::Active === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'subscription.status.draft',
            self::Active => 'subscription.status.active',
            self::Suspended => 'subscription.status.suspended',
            self::Cancelled => 'subscription.status.cancelled',
        };
    }
}
