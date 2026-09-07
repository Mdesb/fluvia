<?php

declare(strict_types=1);

namespace App\Sepa\Enum;

/** Statut d'un mandat SEPA (générique, reprend `App\Sport\Enum\StatutMandatSepaFitness`, plan §5). */
enum StatutMandatSepa: string
{
    case Actif = 'actif';
    /** Mandat signe au comptoir mais dont l'IBAN sera capture plus tard (mode « pending »). Exclu
     *  de toute remise par `GenerationRemiseHandler` tant qu'il n'est pas passe `Actif`. */
    case EnAttente = 'en_attente';
    case Revoque = 'revoque';
}
