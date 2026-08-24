<?php

declare(strict_types=1);

namespace App\Tests\Acces\Support;

use App\Platform\Event\DomainEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Collecteur de l'événement `access.card_recharged` pour les tests CQ-1 (registré uniquement en
 * environnement `test`, `config/services.yaml` bloc `when@test:` — même patron que
 * `App\Tests\Boutique\Support\MailCollector`). `App\Platform\Event\SymfonyEventBus` dispatche
 * `DomainEvent` sous le nom **applicatif** (`$event->name->value`, pas la classe PHP) : la clé de
 * `getSubscribedEvents()` est donc la chaîne du catalogue, pas `DomainEvent::class`.
 *
 * Sert de preuve directe (D7-bis, correctif revue de cohérence) qu'aucun événement n'est publié quand
 * la transaction de `ValiderVenteService::valider()` échoue — le bus étant synchrone, ce collecteur
 * verrait l'événement immédiatement s'il avait été publié avant le rollback.
 */
final class CardRechargedEventCollector implements EventSubscriberInterface
{
    /** @var list<DomainEvent> */
    private array $evenements = [];

    public static function getSubscribedEvents(): array
    {
        return ['access.card_recharged' => 'onCardRecharged'];
    }

    public function onCardRecharged(DomainEvent $event): void
    {
        $this->evenements[] = $event;
    }

    /** @return list<DomainEvent> */
    public function evenements(): array
    {
        return $this->evenements;
    }

    public function reset(): void
    {
        $this->evenements = [];
    }
}
