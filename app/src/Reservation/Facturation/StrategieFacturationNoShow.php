<?php

declare(strict_types=1);

namespace App\Reservation\Facturation;

use App\Reservation\Entity\FacturationNoShow;
use App\Securite\Entity\Utilisateur;

/**
 * Stratégie enfichable de facturation d'un no-show (décision structurante n°4 du plan), résolue par
 * `RegleAnnulation.modeFacturation` via `ResolveurStrategieFacturation` (tag DI
 * `reservation.strategie_facturation_no_show`). Réellement branchées : `VenteDiffereeAgentStrategie`,
 * `DebitPmvStrategie`. Squelettes documentés : `PrelevementDiffereStrategie`, `FactureAEncaisserStrategie`.
 */
interface StrategieFacturationNoShow
{
    public function code(): string;

    /**
     * Applique la stratégie sur une `FacturationNoShow` au statut `à_facturer`. `$agent` est
     * l'utilisateur qui déclenche l'action (requis pour `vente_differee_agent`, absent pour une
     * bascule automatique système).
     *
     * @param array<string, mixed> $contexte
     */
    public function appliquer(FacturationNoShow $facturation, ?Utilisateur $agent, array $contexte = []): ResultatFacturationNoShow;
}
