<?php

declare(strict_types=1);

namespace App\Crm\Enum;

/** Traitement du solde résiduel à l'expiration du PMV, paramétrable par établissement (US-L5-07). */
enum TraitementSoldeResiduel: string
{
    case Conserve = 'conserve';
    case Annule = 'annule';
    case TransformeEnProduit = 'transforme_en_produit';
}
