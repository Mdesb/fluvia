<?php

declare(strict_types=1);

namespace App\Sepa\Event;

/**
 * Émis après une collecte SEPA réussie : la remise est transmise et les échéances de
 * `referencesOrigine` viennent de passer à `Prelevee`.
 *
 * Les modules qui FACTURENT ces échéances (Facturation) s'y branchent pour solder la facture
 * d'échéance et écrire l'encaissement au grand livre — D2 : communication par événement, jamais
 * d'appel direct de module à module. `App\Sepa` ne connaît donc ni la facture ni la comptabilité.
 *
 * On ne porte que des primitives — les références d'origine (ids d'échéances, tels que les factures
 * les portent) et la référence de transmission de la remise — pas l'entité remise : l'abonné n'a pas
 * à connaître le modèle SEPA.
 */
final class EcheancesCollecteesEvent
{
    /** @param list<string> $referencesOrigine ids d'échéances = référence d'origine de leurs factures. */
    public function __construct(
        public readonly array $referencesOrigine,
        public readonly ?string $referenceTransmission,
    ) {
    }
}
