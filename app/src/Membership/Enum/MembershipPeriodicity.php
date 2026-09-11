<?php

declare(strict_types=1);

namespace App\Membership\Enum;

use App\Offre\Enum\PeriodiciteFormule;

/**
 * Cadence de collecte SEPA de l'abonnement d'un adhérent (spec §5).
 *
 * ⚠ VALEURS EN ANGLAIS DEPUIS LE 11/09 (D5), migrées avec celles de `MembershipStatus` :
 * `mensuel -> monthly`, `hebdomadaire -> weekly`, `annuel -> yearly`. La correspondance est écrite
 * ici pour qui relira une sauvegarde antérieure.
 *
 * ⚠ CE COMMENTAIRE DISAIT « DISTINCTE DE `Formule.periodicite` (M1) », ET ELLE NE L'EST PLUS.
 * Arbitrage de Maxime le 06/09 : le contrat GÈLE les termes de l'offre à la signature, comme il
 * gèle déjà le préavis. La distinction avait un coût mesuré : `Formule::$periodicite` est éditable
 * dans l'écran produit et n'était lue nulle part, si bien qu'une formule déclarée ANNUELLE
 * souscrite au guichet devenait MENSUELLE, en silence.
 *
 * ⚠ `PeriodiciteFormule` RESTE EN FRANÇAIS, et ce n'est pas un oubli : elle appartient à `App\Offre`
 * et porte ses propres valeurs persistées. La frontière entre les deux modules est `depuisFormule()`
 * ci-dessous — c'est le seul endroit où les deux vocabulaires se croisent, et il est explicite.
 *
 * Les deux énumérations ne se recouvrent toujours pas complètement, et c'est voulu :
 *   — `Weekly` n'existe pas côté formule. Aucun abonnement hebdomadaire n'existe (0 en
 *     préproduction) et rien ne l'écrit ; le cas reste pour ne pas invalider une ligne en base.
 *   — `Personnalise` n'existe pas ici, et n'est cité NULLE PART dans le dépôt. On refuse plutôt
 *     que d'en inventer le sens.
 */
enum MembershipPeriodicity: string
{
    case Monthly = 'monthly';
    case Weekly = 'weekly';
    case Yearly = 'yearly';

    /**
     * La cadence du contrat, telle que la formule la déclare.
     *
     * ⚠ `null` POUR `Personnalise`, ET C'EST UN REFUS, PAS UN DÉFAUT. Ce cas n'est employé nulle
     * part dans le dépôt : lui choisir une cadence ici serait inventer une règle commerciale, et
     * l'appliquer en silence à toutes les souscriptions d'une telle formule. L'appelant refuse et
     * le dit.
     *
     * ⚠ ET C'EST UN `match` EXHAUSTIF À DESSEIN : le jour où `PeriodiciteFormule` gagne un cas, PHP
     * refuse de compiler ce fichier plutôt que de le traiter comme `Personnalise`.
     */
    public static function depuisFormule(PeriodiciteFormule $formule): ?self
    {
        return match ($formule) {
            PeriodiciteFormule::Mensuel => self::Monthly,
            PeriodiciteFormule::Annuel => self::Yearly,
            PeriodiciteFormule::Personnalise => null,
        };
    }

    /** Le pas d'une échéance à la suivante. */
    public function increment(): string
    {
        return match ($this) {
            self::Monthly => '+1 month',
            self::Weekly => '+1 week',
            self::Yearly => '+1 year',
        };
    }

    /**
     * Un « jour du mois » a-t-il un sens pour cette cadence ?
     *
     * Mensuel et annuel, oui : le 5 du mois, ou le 5 du mois anniversaire. Hebdomadaire, non.
     */
    public function porteUnJourDuMois(): bool
    {
        return $this !== self::Weekly;
    }
}
