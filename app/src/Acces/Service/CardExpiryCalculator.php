<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Offre\Entity\CarteMultiEntrees;
use App\Offre\Enum\RechargeValidityMode;

/**
 * Calcule la nouvelle échéance de validité d'une carte multi-entrées (RG-CQ1-04, D26 — défaut livré :
 * prolongation, une période complète depuis maintenant). Service pur, sans dépendance Doctrine :
 * partagé entre `CardRechargeHandler` (recharge) et `StubProjectionDroit` (émission initiale, T6 —
 * bundle de cohérence, cf. spec §3 pt.4).
 */
final class CardExpiryCalculator
{
    /**
     * CQ-7 (RG-CQ7-03) — le point d'extension anticipé par CQ-1 est réalisé ici : en mode `Keep`, la
     * recharge **conserve** l'échéance existante (`$fenetreFinActuelle` retournée telle quelle). Le
     * garde `$fenetreFinActuelle !== null` limite le mode à la RECHARGE : à l'émission initiale, où
     * `$fenetreFinActuelle` est `null` par construction (aucune échéance préalable), le calcul normal
     * ci-dessous s'applique quel que soit le mode. Une carte `Keep` déjà expirée reste expirée (succès
     * silencieux, échéance non réactivée — RG-CQ7-04, arbitrage A : recommandé plutôt qu'un refus).
     */
    public function calculer(
        CarteMultiEntrees $carte,
        ?\DateTimeImmutable $fenetreFinActuelle,
        \DateTimeImmutable $maintenant,
    ): ?\DateTimeImmutable {
        if ($carte->getRechargeValidityMode() === RechargeValidityMode::Keep && $fenetreFinActuelle !== null) {
            return $fenetreFinActuelle;
        }

        $duree = $carte->getValiditeDuree();
        $butoir = $carte->getDateButoir();

        if ($duree === null && $butoir === null) {
            return null; // carte illimitée, comportement actuel inchangé.
        }
        if ($duree === null) {
            return $butoir; // seul le plafond fixe : ne recule jamais à chaque recharge.
        }

        $candidate = $maintenant->add($duree);

        return $butoir !== null ? min($candidate, $butoir) : $candidate;
    }
}
