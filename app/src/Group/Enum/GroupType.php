<?php

declare(strict_types=1);

namespace App\Group\Enum;

/**
 * Nature d'un groupe de participants. Anglais (D5) ; le libellé affiché relève de la couche i18n /
 * du vocabulaire par verticale (`ModuleManifest::settingsSchema()`), pas de cet enum.
 */
enum GroupType: string
{
    case School = 'school';
    case Tour = 'tour';
    case WorksCouncil = 'works_council';
    case Association = 'association';
    case Other = 'other';
}
