<?php

declare(strict_types=1);

namespace App\Vente\Port;

use App\Vente\Entity\Vente;

/**
 * Frontière module Abonnement (même patron que CardRechargeInterface / AppairageAccesInterface) : à
 * partir d'une vente comptoir VALIDÉE (donc déjà scellée NF525), crée les abonnements correspondant
 * à ses lignes portant un produit à facette Formule. Définie côté App\Vente, ne référence AUCUNE
 * entité App\Membership / App\Sepa (inversion de dépendance) ; l'implémentation vit dans
 * App\Membership\Adapter\SaleSubscriptionAdapter.
 *
 * ⚠ APPELÉE APRÈS LE COMMIT DE LA VENTE (ValiderVenteProcessor, patron D7-bis / boutique en ligne
 *   `SouscriptionAbonnementEnLigneHandler` l.171→193) — JAMAIS dans la transaction de scellement
 *   NF525 (D45). C'est la correction du montage de l'ancienne branche `feature/caisse-abonnement`,
 *   qui créait l'abonnement DANS la transaction scellée (un refus y faisait rollback le scel, ce
 *   que G-5 interdit). Un refus ici (ligne à quantité > 1, bénéficiaire hors périmètre) lève une
 *   exception qui NE FAIT PAS rollback : la vente reste Validée et scellée, l'API renvoie l'erreur,
 *   et la reprise se fait hors de cette transaction.
 *
 * ⚠ SAUF CE QUI SE SAIT AVANT (décision de Maxime du 07/10, qui revoit G-5) : `assertSubscribable()`
 *   est appelée AVANT le scellement, et refuse une vente qui ouvre un abonnement sans client payeur.
 *   Scellée, elle encaissait sans abonnement possible ni reprise (aucun débiteur pour le mandat).
 */
interface SaleSubscriptionInterface
{
    public function assertSubscribable(Vente $vente): void;

    public function createSubscriptionsFromSale(Vente $vente): void;

    /**
     * LA VENTE EST ANNULÉE OU REMBOURSÉE EN TOTALITÉ : les abonnements que ses lignes ont créés sont
     * résiliés avec elle, sans frais, au motif donné (« Vente annulée (…) », « Vente remboursée (…) »)
     * (décisions de Maxime du 07/10 et du 08/10). Celui qu'elle a seulement payé au comptoir reste
     * actif, et son premier mois redevient dû. Appelée après le commit de l'avoir, comme la création ;
     * rejouable, un abonnement déjà résilié est laissé tel quel.
     */
    public function terminateSubscriptionsFromSale(Vente $vente, string $motif): void;
}
