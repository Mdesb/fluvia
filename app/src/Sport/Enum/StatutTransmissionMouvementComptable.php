<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/** Statut de transmission d'un mouvement comptable en file d'attente (§1.8/§2.4 du plan). */
enum StatutTransmissionMouvementComptable: string
{
    case EnAttente = 'en_attente';
    case Transmis = 'transmis';
}
