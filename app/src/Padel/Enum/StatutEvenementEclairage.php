<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Statut d'un événement d'éclairage tracé (§4.9). */
enum StatutEvenementEclairage: string
{
    case Ok = 'ok';
    case EchecRepliManuel = 'echec_repli_manuel';
}
