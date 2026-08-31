<?php

declare(strict_types=1);

namespace App\Facturation\Enum;

/**
 * Distinction structurante de `spec-facturation.md` §5 (RG-FACT-05) : une facture, ou un avoir —
 * seule voie de correction d'une facture émise. Pilote la série de numérotation légale (§4.1) et
 * le sens de l'écriture comptable éventuelle (§4.6).
 */
enum NatureFacture: string
{
    case Facture = 'facture';
    case Avoir = 'avoir';
    /**
     * Facture d'ACOMPTE : elle constate une somme percue d'avance, avec sa TVA.
     *
     * Elle est deduite de la facture de solde a l'emission de celle-ci, ligne a ligne. Sans cette
     * deduction, le client serait facture deux fois -- une fois l'acompte, une fois le total.
     */
    case Acompte = 'acompte';
}
