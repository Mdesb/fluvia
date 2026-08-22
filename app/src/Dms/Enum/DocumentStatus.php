<?php

declare(strict_types=1);

namespace App\Dms\Enum;

/**
 * Statut d'un `Document` (RG-DMS-14) : `Active` par défaut, `Deleted` après suppression logique.
 * La purge physique différée (`PurgeDocumentsCommand`) ne change jamais ce statut — elle retire
 * uniquement le contenu binaire des versions (RG-DMS-15).
 */
enum DocumentStatus: string
{
    case Active = 'active';
    case Deleted = 'deleted';
}
