<?php

declare(strict_types=1);

namespace App\Import\Enum;

/**
 * Type d'import repris (`App\Import`, plan-import-i1.md §1, D5 — valeurs anglaises). Seul `Customers`
 * est implémenté par ce lot (I1, `App\Import\Service\CustomerRowImporter`) — les types suivants
 * (`products`, `tariffs`, `subscribers`, `card_credits`, `staff`, SPEC-REPRISE-INITIALE.md §4) sont des
 * incréments futurs (I2+), volontairement absents comme cas d'enum tant qu'aucun `RowImporter` ne les
 * implémente : un cas d'enum sans implémentation route droit vers un 422 muet plutôt qu'une intention
 * lisible (`RowImporterRegistry::forType()`).
 */
enum ImportType: string
{
    case Customers = 'customers';

    // I2+ (non implémentés par ce lot, §0.3 du plan) :
    // case Products = 'products';
    // case Tariffs = 'tariffs';
    // case Subscribers = 'subscribers';
    // case CardCredits = 'card_credits';
    // case Staff = 'staff';
}
