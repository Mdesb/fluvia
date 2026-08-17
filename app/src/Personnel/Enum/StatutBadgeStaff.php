<?php

declare(strict_types=1);

namespace App\Personnel\Enum;

/**
 * Statut RH du badge staff (RG-PERSO-06/08, décision n°7 du plan) — dénormalisé par rapport à
 * `App\Acces\Enum\StatutSupport` (2 états `actif`/`bloque`) : les 2 causes RH `suspendu`/`revoque`
 * mappent toutes deux sur `Support.statut = bloque`, distinguées ici uniquement.
 */
enum StatutBadgeStaff: string
{
    case Actif = 'actif';
    case Suspendu = 'suspendu';
    case Revoque = 'revoque';
}
