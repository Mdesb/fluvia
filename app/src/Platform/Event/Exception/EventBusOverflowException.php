<?php

declare(strict_types=1);

namespace App\Platform\Event\Exception;

/**
 * Profondeur de publication dépassée (spec §7, « Réentrance »).
 *
 * Le bus est synchrone : un abonné qui publie un événement qui redéclenche le premier boucle jusqu'à
 * épuiser la pile. On préfère une erreur explicite et nommée à un `stack overflow` illisible.
 */
final class EventBusOverflowException extends \RuntimeException
{
}
