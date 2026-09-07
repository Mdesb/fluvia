<?php

declare(strict_types=1);

namespace App\Vente\Port;

use App\Vente\Entity\Vente;

/**
 * Frontiere module Abonnement (meme patron que CardRechargeInterface / AppairageAccesInterface) : a
 * partir d'une vente validee au guichet, cree les abonnements correspondant a ses lignes
 * produit-abonnement. Definie cote App\Vente, elle ne reference AUCUNE entite App\Sport / App\Sepa
 * (inversion de dependance) ; l'implementation vit dans App\Sport\Adapter\SaleSubscriptionAdapter.
 *
 * ⚠ Appelee par ValiderVenteProcessor DANS la transaction englobante du scellement NF525. Chaque
 * refus (ligne a quantite > 1, vente anonyme, beneficiaire/mandat hors perimetre, mandat requis
 * absent) leve une exception qui fait ROLLBACK le scellement : la vente reste EnCours, rejouable, et
 * l'API renvoie une 422 honnete (rien n'a ete scelle). Jamais de creation silencieuse, jamais de
 * vente scellee sans son abonnement.
 */
interface SaleSubscriptionInterface
{
    public function createSubscriptionsFromSale(Vente $vente, MandateChoice $mandate): void;
}
