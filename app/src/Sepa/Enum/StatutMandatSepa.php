<?php

declare(strict_types=1);

namespace App\Sepa\Enum;

/** Statut d'un mandat SEPA (générique, reprend `App\Sport\Enum\StatutMandatSepaFitness`, plan §5). */
enum StatutMandatSepa: string
{
    case Actif = 'actif';
    /**
     * Mandat créé sans IBAN, à compléter plus tard par un lien de signature (spec-caisse-abonnement
     * CP-1 G-3, plan CP-2 É5 — pas encore câblé : ce lot-ci, É1/É2, s'arrête à la création de ce
     * mandat). Exclu de toute remise par `GenerationRemiseHandler` tant qu'il n'est pas passé
     * `Actif`.
     */
    case EnAttente = 'en_attente';
    case Revoque = 'revoque';
}
