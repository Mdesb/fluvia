<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;

/**
 * Les événements d'un règlement, retenus jusqu'au commit (D7-bis, G-5 du ticket opposable).
 *
 * Le bus est synchrone : publier au milieu de l'unité ferait voir à un abonné un fait que la
 * transaction peut encore annuler. Celui qui ouvre la transaction publie, après le commit ; une
 * transaction annulée abandonne simplement la liste.
 */
final class SettlementEvents
{
    /**
     * Posé par `PaiementHandler` juste avant l'appel au terminal. En deçà, une erreur n'a rien pu
     * débiter ; au-delà, seule une réponse du terminal dit ce qui s'est passé.
     */
    public bool $terminalAsked = false;

    /** @var list<DomainEvent> */
    private array $events = [];

    public function add(DomainEvent $event): void
    {
        $this->events[] = $event;
    }

    /** Publie ce qui a été retenu, puis l'oublie. À n'appeler qu'après le commit. */
    public function publishTo(EventBus $bus): void
    {
        [$events, $this->events] = [$this->events, []];
        foreach ($events as $event) {
            $bus->publish($event);
        }
    }
}
