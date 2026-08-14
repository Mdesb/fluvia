<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/** Nature du droit projeté (RG-M1-03/04) : pilote le décompte de crédit au passage (RG-ACC-02). */
enum TypeDroitAcces: string
{
    case Billet = 'billet';
    case Abonnement = 'abonnement';
    case CarteQuota = 'carte_quota';
}
