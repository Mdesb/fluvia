<?php

declare(strict_types=1);

namespace App\Vente\Port;

/**
 * Frontière M6 (hors périmètre L2) : référentiel des moyens de paiement et acte de régie qui les
 * filtre (RG-M2-02). En L2, un stub fournit le jeu standard ; le câblage réel au référentiel M6
 * intervient à l'intégration (L4). Le code du moyen reste stocké en clair sur le Paiement.
 */
interface ReferentielReglementInterface
{
    /** @return array<string, MoyenPaiement> indexé par code */
    public function moyensDisponibles(): array;

    public function moyen(string $code): ?MoyenPaiement;
}
