<?php

declare(strict_types=1);

namespace App\Platform\Notification;

/**
 * Les canaux par lesquels on peut atteindre un client.
 *
 * **Les valeurs sont alignées sur `Crm\Enum\CanalConsentement` à dessein.** Le consentement se donne
 * par canal ; si les deux énumérations divergeaient, on pourrait envoyer sur un canal pour lequel
 * aucun consentement n'est concevable. L'alignement n'est pas une commodité, c'est ce qui rend la
 * vérification possible.
 */
enum NotificationChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Courrier = 'courrier';
}
