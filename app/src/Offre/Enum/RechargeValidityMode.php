<?php

declare(strict_types=1);

namespace App\Offre\Enum;

/**
 * Mode de renouvellement de la validité d'une carte multi-entrées **à la recharge** (CQ-7,
 * `RG-CQ7-01`). Paramétré par produit-carte (`CarteMultiEntrees`). Sans effet sur l'émission initiale,
 * qui calcule toujours la validité de départ normalement.
 */
enum RechargeValidityMode: string
{
    /**
     * Défaut — comportement livré par CQ-1 (D26) : la recharge repousse l'échéance à « maintenant + une
     * période complète » (`validiteDuree`), plafonnée par `dateButoir`. Non-régression.
     */
    case Extend = 'extend';

    /**
     * La recharge **conserve** l'échéance existante : elle ajoute des crédits sans repousser la date
     * d'expiration. `dateButoir` devient sans objet à la recharge (rien n'est recalculé, RG-CQ7-05).
     */
    case Keep = 'keep';
}
