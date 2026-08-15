<?php

declare(strict_types=1);

namespace App\Crm\Enum;

/** Statut du porte-monnaie virtuel (RG-M4-03/04). `expire` = non proposable en caisse (CA-10). */
enum StatutPmv: string
{
    case Actif = 'actif';
    case Expire = 'expire';
}
