<?php

declare(strict_types=1);

namespace App\Platform\Notification;

/**
 * Ce qu'il est advenu d'une notification.
 *
 * **`Refusee` n'est pas une erreur.** Un client sans consentement, ou au plafond de sollicitation, est
 * un cas **normal** et fréquent : il doit se compter, s'afficher et s'expliquer (D42, RG-CMP-08), pas
 * lever une exception que l'appelant finirait par attraper et ignorer.
 *
 * **`Journalisee` n'est pas `Envoyee`, et la distinction est capitale.** Tant qu'aucun prestataire
 * n'est branché, l'adaptateur par défaut écrit dans un journal. Rendre `Envoyee` dans ce cas ferait
 * croire à un exploitant que son message est parti. On ne laisse jamais croire ça (D43, même esprit).
 */
enum NotificationOutcome: string
{
    case Envoyee = 'envoyee';
    case Journalisee = 'journalisee';
    case Refusee = 'refusee';
    case Echouee = 'echouee';
}
