<?php

declare(strict_types=1);

namespace App\Platform\Event;

use App\Platform\Event\Exception\EventBusOverflowException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Bus synchrone in-process, au-dessus de l'`EventDispatcher` Symfony (D7).
 *
 * Aucune dépendance ajoutée : `symfony/messenger` n'est pas installé et le dispatcher est déjà utilisé
 * par cinq modules du socle. L'asynchrone n'est pas un objectif de la v0 — le jour où un abonné lent
 * ralentira un émetteur, ce sera le signal qui justifiera la file, pas avant.
 *
 * **Abonnement par nom.** On dispatche toujours la même classe ({@see DomainEvent}) en passant
 * `$event->name` comme nom d'événement du dispatcher. Un module s'abonne donc à `invoice.overdue`,
 * une chaîne du contrat, et n'importe jamais le code de l'émetteur (D2).
 */
final class SymfonyEventBus implements EventBus
{
    /**
     * Profondeur de publication maximale (spec §7, « Réentrance »).
     *
     * Une chaîne de réactions légitime reste courte : un fait en déclenche un autre, rarement au-delà
     * de deux ou trois niveaux. Huit laisse de la marge tout en attrapant la boucle avant qu'elle
     * n'épuise la pile — et l'erreur nomme l'événement fautif, ce qu'un `stack overflow` ne fait pas.
     */
    public const DEFAULT_MAX_DEPTH = 8;

    private int $depth = 0;

    /** @var list<string> pile des noms en cours de publication, pour un message d'erreur lisible */
    private array $publishing = [];

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly int $maxDepth = self::DEFAULT_MAX_DEPTH,
    ) {
    }

    public function publish(DomainEvent $event): void
    {
        if ($this->depth >= $this->maxDepth) {
            throw new EventBusOverflowException(sprintf(
                'Profondeur de publication dépassée (%d) en publiant « %s ». Chaîne en cours : %s. '
                .'Un abonné republie un événement qui redéclenche le premier — le bus étant synchrone, '
                .'la boucle est infinie.',
                $this->maxDepth,
                $event->name->value,
                implode(' → ', [...$this->publishing, $event->name->value]),
            ));
        }

        ++$this->depth;
        $this->publishing[] = $event->name->value;

        try {
            $this->dispatcher->dispatch($event, $event->name->value);
        } finally {
            // `finally` et non `catch` : l'exception d'un abonné doit remonter intacte à l'émetteur
            // (RG-PLAT-05). On rétablit seulement le compteur, sinon une exception laisserait le bus
            // durablement « profond » et ferait échouer les publications suivantes de la requête.
            --$this->depth;
            array_pop($this->publishing);
        }
    }
}
