<?php

declare(strict_types=1);

namespace App\Offre\Enum;

/**
 * Ce qu'une recharge fait à la validité d'une carte multi-entrées (D26, CQ-7).
 *
 * C'est un paramètre du **produit-carte**, et pas une règle globale : deux exploitants du même
 * logiciel n'ont pas la même politique commerciale, et le même exploitant peut vouloir prolonger
 * sur une offre d'appel et pas sur une autre.
 */
enum RechargeValidityMode: string
{
    /**
     * Défaut livré (D26). La recharge repart pour **une période complète à compter de la recharge**
     * — « vous rechargez, vous repartez pour un an » —, et non pour un ajout à l'échéance existante.
     * Le plafond `dateButoir`, lui, continue de s'appliquer.
     */
    case Extend = 'extend';

    /**
     * La validité d'origine tient : la recharge ajoute du crédit et ne touche pas à l'échéance.
     * C'est l'option prévue par D26 pour l'exploitant qui ne veut pas de prolongation.
     */
    case Keep = 'keep';
}
