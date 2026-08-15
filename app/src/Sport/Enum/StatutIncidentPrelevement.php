<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/** Cycle du dossier impayé (RG-SPORT-01/02, §0/§4.5 du plan). */
enum StatutIncidentPrelevement: string
{
    case Representation = 'representation';
    case Recouvrement = 'recouvrement';
    case Resolu = 'resolu';
}
