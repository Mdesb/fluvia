<?php

declare(strict_types=1);

namespace App\Platform\Event\Exception;

/**
 * Enveloppe d'événement mal formée (RG-PLAT-01/02/03/04).
 *
 * C'est une **erreur de programmation**, pas une erreur métier : un module qui construit une enveloppe
 * incomplète a un bug, on ne la « répare » pas silencieusement. Exception dédiée plutôt que
 * `\InvalidArgumentException` nue pour que les tests et un éventuel garde-fou CI puissent la cibler.
 */
final class InvalidDomainEventException extends \InvalidArgumentException
{
}
