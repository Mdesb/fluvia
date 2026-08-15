<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/** Résultat d'une représentation SEPA (RG-SPORT-02) : un échec bascule le dossier en recouvrement. */
enum ResultatRepresentationSepa: string
{
    case EnAttente = 'en_attente';
    case Reussie = 'reussie';
    case Echouee = 'echouee';
}
