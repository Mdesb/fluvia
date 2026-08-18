<?php

declare(strict_types=1);

namespace App\Facturation\Enum;

/**
 * Cycle de vie d'une facture (RG-FACT-04, `plan-facturation.md` §1.1) :
 * `brouillon` → `emise` puis, selon l'origine (RG-FACT-03) :
 *  - justificative → `acquittee` directement (aucune attente de paiement) ;
 *  - directe → `en_attente_paiement` → `partiellement_reglee` → `payee`, ou `echue` (informatif).
 *
 * En pratique, l'état `emise` n'est jamais persisté seul : dans la même transaction d'émission, le
 * statut est immédiatement résolu vers `acquittee` ou `en_attente_paiement` (RG-FACT-03/04). Il reste
 * néanmoins modélisé (table du plan §1.1) pour représenter le passage « numéroté + scellé ».
 *
 * Aucun état « annulé » : une facture émise ne s'annule jamais, elle se corrige par un **avoir**
 * (RG-FACT-05, constitution §4.5).
 */
enum StatutFacture: string
{
    case Brouillon = 'brouillon';
    case Emise = 'emise';
    case Acquittee = 'acquittee';
    case EnAttentePaiement = 'en_attente_paiement';
    case PartiellementReglee = 'partiellement_reglee';
    case Payee = 'payee';
    case Echue = 'echue';

    /** Vrai tant que la facture est librement modifiable (aucun numéro consommé). */
    public function estBrouillon(): bool
    {
        return $this === self::Brouillon;
    }
}
