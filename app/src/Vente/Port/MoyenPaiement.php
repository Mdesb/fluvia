<?php

declare(strict_types=1);

namespace App\Vente\Port;

/**
 * Objet-valeur « moyen de paiement » lu du référentiel M6 (RG-M2-02) — n'est PAS une entité M2.
 * Le code du moyen est stocké en clair sur le Paiement (pas de FK dure vers M6).
 */
final class MoyenPaiement
{
    public function __construct(
        public readonly string $code,
        public readonly string $libelle,
        public readonly bool $autoriseRendu = false,
        public readonly bool $exigeReference = false,
        public readonly bool $autoriseDiffere = false,
    ) {
    }
}
