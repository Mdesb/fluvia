<?php

declare(strict_types=1);

namespace App\Subscription\Exception;

/**
 * Passage d'état interdit sur un abonnement (ED-2).
 *
 * Le message nomme toujours les états réellement atteignables depuis l'état courant : celui qui
 * déclenche l'erreur cherche presque toujours à faire quelque chose de légitime par un mauvais chemin,
 * et lui montrer les chemins valides lui coûte moins cher que de relire l'énumération.
 */
final class InvalidSubscriptionTransitionException extends \LogicException
{
}
