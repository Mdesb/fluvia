<?php

declare(strict_types=1);

namespace App\Recouvrement\Enum;

/** Cycle du dossier impayé (moteur générique de recouvrement, décision actée). */
enum StatutIncidentImpaye: string
{
    case Representation = 'representation';
    case Recouvrement = 'recouvrement';
    case Resolu = 'resolu';
}
