<?php

declare(strict_types=1);

namespace App\Platform\Enum;

/**
 * TROIS NIVEAUX, PARCE QUE TROIS GESTES.
 *
 * `Info` se lit, `Attention` se planifie, `Critical` s'interrompt. Un quatrième niveau n'ajouterait
 * pas de finesse : il obligerait à trancher entre deux nuances qui appellent la même action, et le
 * doute se résoudrait vers le haut — c'est ainsi que tout finit en rouge et que le rouge ne veut
 * plus rien dire.
 */
enum NotificationSeverity: string
{
    case Info = 'info';
    case Warning = 'attention';
    case Critical = 'critique';
}
