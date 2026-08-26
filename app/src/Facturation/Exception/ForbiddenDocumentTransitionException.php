<?php

declare(strict_types=1);

namespace App\Facturation\Exception;

/**
 * Passage d'état refusé sur une pièce commerciale (FAC-1).
 *
 * Exception dédiée pour que l'appelant distingue « ce passage n'existe pas » d'une panne : le premier
 * se corrige en regardant l'état réel de la pièce, le second en regardant les journaux. Et exception
 * plutôt que valeur de retour ignorable : un refus qu'on peut oublier de tester est un refus qui ne
 * protège rien.
 */
final class ForbiddenDocumentTransitionException extends \DomainException
{
}
