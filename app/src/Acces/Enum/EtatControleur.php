<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/** Cycle de vie réseau d'un contrôleur (§4.6, RG-ACC-05) : bascule online/offline automatique. */
enum EtatControleur: string
{
    case EnLigne = 'en_ligne';
    case HorsLigne = 'hors_ligne';
    case HorsService = 'hors_service';
}
