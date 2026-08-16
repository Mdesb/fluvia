<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Statut de retour d'une location de matériel (US-PADEL-08). */
enum StatutRetourMateriel: string
{
    case EnCours = 'en_cours';
    case Rendu = 'rendu';
    case NonRendu = 'non_rendu';
}
