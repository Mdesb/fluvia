<?php

declare(strict_types=1);

namespace App\Boutique\Enum;

/** Type de session client final anonyme (§4.4 spec-boutique.md, RG-M3-06). */
enum TypeSessionClient: string
{
    case Invite = 'invite';
    case FranceconnectEnCours = 'franceconnect_en_cours';
}
