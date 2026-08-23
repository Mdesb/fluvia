<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Offre\Entity\CarteMultiEntrees;

/**
 * Calcule la nouvelle échéance de validité d'une carte multi-entrées (RG-CQ1-04, D26 — défaut livré :
 * prolongation, une période complète depuis maintenant). Service pur, sans dépendance Doctrine :
 * partagé entre `CardRechargeHandler` (recharge) et `StubProjectionDroit` (émission initiale, T6 —
 * bundle de cohérence, cf. spec §3 pt.4).
 */
final class CardExpiryCalculator
{
    /**
     * `$fenetreFinActuelle` n'intervient dans AUCUNE branche du calcul par défaut (CA-3 : « J + 1 an »,
     * pas « ancienne échéance + 1 an ») — il n'existe que comme point d'extension CQ-7 (paramétrage
     * « conserver la validité d'origine », hors périmètre de ce lot) : un futur appelant pourrait
     * court-circuiter cette méthode en amont pour retourner `$fenetreFinActuelle` telle quelle sans
     * jamais avoir à réécrire ce calcul.
     */
    public function calculer(
        CarteMultiEntrees $carte,
        ?\DateTimeImmutable $fenetreFinActuelle,
        \DateTimeImmutable $maintenant,
    ): ?\DateTimeImmutable {
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
