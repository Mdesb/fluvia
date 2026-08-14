<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/** Mode d'appairage (A-02) : à la caisse (M2) ou en borne autonome libre-service. */
enum ModeAppairage: string
{
    case Caisse = 'caisse';
    case Autonome = 'autonome';
}
