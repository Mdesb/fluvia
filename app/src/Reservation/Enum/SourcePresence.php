<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/** Source de confirmation de présence (§4.8). */
enum SourcePresence: string
{
    case EmargementManuel = 'emargement_manuel';
    case PassageAcces = 'passage_acces';
}
