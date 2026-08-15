<?php

declare(strict_types=1);

namespace App\Recouvrement\Enum;

/** Résultat d'une représentation SEPA (moteur générique de recouvrement) : un échec bascule le dossier en recouvrement. */
enum ResultatRepresentationSepa: string
{
    case EnAttente = 'en_attente';
    case Reussie = 'reussie';
    case Echouee = 'echouee';
}
