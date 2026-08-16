<?php

declare(strict_types=1);

namespace App\Musee\Enum;

/** Cycle du paiement différé d'un dossier groupe/scolaire (⚠ HYPOTHÈSE, §4.6). */
enum StatutPaiementDossier: string
{
    case EnOption = 'en_option';
    case BonCommandeEmis = 'bon_commande_emis';
    case MandatEmis = 'mandat_emis';
    case Paye = 'paye';
}
