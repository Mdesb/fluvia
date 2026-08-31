<?php

declare(strict_types=1);

namespace App\Calendar\Enum;

/**
 * Nature d'un `CalendarEvent` saisi à la main.
 *
 * Volontairement COURTE. Un catalogue de vingt natures se remplit une fois puis n'est plus jamais
 * choisi correctement : tout finit en « Autre », et la couleur ne dit plus rien. Cinq entrées
 * couvrent ce qu'un exploitant note vraiment sur un agenda de site.
 */
enum CalendarEventType: string
{
    case Meeting = 'meeting';
    case Maintenance = 'maintenance';
    case Training = 'training';
    case Unavailability = 'unavailability';
    case Other = 'other';
}
