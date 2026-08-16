<?php

declare(strict_types=1);

namespace App\Boutique\Enum;

/** Cycle de vie d'un panier en ligne (RG-M3-03, §4.3 spec-boutique.md). */
enum StatutPanier: string
{
    case Ouvert = 'ouvert';
    case Expire = 'expire';
    case TransformeEnCommande = 'transforme_en_commande';
}
