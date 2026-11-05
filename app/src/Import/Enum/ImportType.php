<?php

declare(strict_types=1);

namespace App\Import\Enum;

/**
 * Type d'import repris (`App\Import`, plan-import-i1.md §1, D5 — valeurs anglaises). Chaque cas a son
 * `RowImporter` : `customers` (`CustomerRowImporter`, I1), `subscribers` (`SubscriptionRowImporter`) et
 * `card_credits` (`CardCreditRowImporter`), ces deux derniers au #348.
 *
 * Les types suivants (`products`, `tariffs`, `staff`, SPEC-REPRISE-INITIALE.md §4) restent des
 * incréments futurs, volontairement absents comme cas d'enum tant qu'aucun `RowImporter` ne les
 * implémente : un cas d'enum sans implémentation route droit vers un 422 muet plutôt qu'une intention
 * lisible (`RowImporterRegistry::forType()`).
 */
enum ImportType: string
{
    case Customers = 'customers';
    case Subscribers = 'subscribers';
    case CardCredits = 'card_credits';

    // Non implémentés (§0.3 du plan) :
    // case Products = 'products';
    // case Tariffs = 'tariffs';
    // case Staff = 'staff';
}
