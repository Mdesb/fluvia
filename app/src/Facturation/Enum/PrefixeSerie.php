<?php

declare(strict_types=1);

namespace App\Facturation\Enum;

/**
 * Préfixe de la série de numérotation légale (RG-FACT-01, `plan-facturation.md` §1.4) : deux séries
 * distinctes et indépendantes, une pour les factures, une pour les avoirs — même précédent que M2
 * (`Vente::$numero` vs `Avoir::$numero`, `GenerateurNumero`).
 */
enum PrefixeSerie: string
{
    case Facture = 'FA';
    case Avoir = 'AVF';
}
