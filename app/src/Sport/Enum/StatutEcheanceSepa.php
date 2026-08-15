<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/** Statut d'une échéance de l'échéancier SEPA. `Gelee` = pause (RG-SPORT-05). */
enum StatutEcheanceSepa: string
{
    case AVenir = 'a_venir';
    case Prelevee = 'prelevee';
    case Rejetee = 'rejetee';
    case Gelee = 'gelee';
}
