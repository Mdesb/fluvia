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
 *   que G-5 interdit). Un refus ici (ligne à quantité > 1, vente anonyme, bénéficiaire hors
 *   périmètre) lève une exception qui NE FAIT PAS rollback : la vente reste Validée et scellée,
 *   l'API renvoie l'erreur, et la reprise se fait hors de cette transaction.
 */
interface SaleSubscriptionInterface
{
    public function createSubscriptionsFromSale(Vente $vente): void;
}
