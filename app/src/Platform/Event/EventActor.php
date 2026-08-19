<?php

declare(strict_types=1);

namespace App\Platform\Event;

use Symfony\Component\Uid\Uuid;

/**
 * Qui a déclenché le fait.
 *
 * L'absence d'acteur se représente par `null` sur {@see DomainEvent::$actor} — jamais par un
 * `EventActor` porteur d'un identifiant creux. « Le système » (tâche planifiée, réaction en chaîne
 * d'un autre événement) est une information utile à l'audit, pas un trou à combler.
 */
final class EventActor
{
    public readonly Uuid $userId;

    public function __construct(Uuid $userId)
    {
        $this->userId = $userId;
    }

    public function equals(self $other): bool
    {
        return $this->userId->equals($other->userId);
    }
}
