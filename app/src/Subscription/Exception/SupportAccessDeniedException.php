<?php

declare(strict_types=1);

namespace App\Subscription\Exception;

/**
 * Lecture refusée faute d'accès d'assistance utilisable (ED-4, RG-ED-07, CA-6).
 *
 * Exception dédiée pour que l'appelant réponde 403 ou 404 selon ce qu'il veut révéler, plutôt que de
 * tomber en 500. Et exception plutôt que booléen sur le chemin de décision : un refus qu'on peut
 * ignorer par distraction — en oubliant de tester la valeur de retour — est un refus qui ne protège
 * rien.
 */
final class SupportAccessDeniedException extends \RuntimeException
{
}
