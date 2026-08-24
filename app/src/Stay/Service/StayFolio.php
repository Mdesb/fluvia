<?php

declare(strict_types=1);

namespace App\Stay\Service;

use App\Stay\Entity\Stay;

/**
 * La note du séjour : ce que le client doit, à l'instant où on la demande (ACT-3, D16).
 *
 * Le total se **dérive** des lignes à chaque appel — il n'est jamais stocké sur `Stay`. Un compteur
 * dénormalisé dérive dès la première ligne annulée, corrigée ou rejouée, et personne ne s'en aperçoit
 * avant qu'un client ne conteste sa note. Une note de séjour compte quelques dizaines de lignes : le
 * coût du calcul est sans commune mesure avec le coût d'un total faux.
 */
final class StayFolio
{
    public function __construct(private readonly StayChargeLookup $lookup)
    {
    }

    public function balanceOf(Stay $stay): StayBalance
    {
        return StayBalance::fromAmounts($this->lookup->amountsOf($stay));
    }
}
