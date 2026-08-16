<?php

declare(strict_types=1);

namespace App\Musee\Enum;

/** Mécanique de délestage d'une salle saturée (⚠ HYPOTHÈSE, non détaillée par les sources, §4.2). */
enum ModeDelestage: string
{
    case FileAttenteSurPlace = 'file_attente_sur_place';
    case RedirectionParcours = 'redirection_parcours';
    case AlerteSeule = 'alerte_seule';
}
