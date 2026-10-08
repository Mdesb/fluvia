<?php

declare(strict_types=1);

namespace App\Vente\Port;

use App\Organisation\Entity\Etablissement;
use Symfony\Component\Uid\Uuid;

/**
 * Frontière M6 : le taux de TVA qu'une ligne de vente doit porter (D123, Q-B1 ; spec ticket opposable,
 * G-7 et G-9).
 *
 * Le taux vient de la correspondance comptable de la catégorie du produit, la même que lisent les
 * écritures de vente, et elle doit être valide (compte de produit et taux actifs). `Produit::$tauxTva`
 * n'est jamais lu : il sert aux échéances d'abonnement (U-1), et en préprod il contredisait la
 * correspondance (10 % contre 20 %).
 *
 * Deux lecteurs, une seule réponse : `LineLabelStamper` grave ce qui est résolu, et chaque créateur de
 * ligne refuse ce qui ne l'est pas, avant tout paiement (`LineVatRateGuard`).
 */
interface SaleFiscalContextInterface
{
    public function vatRateFor(Uuid $productId, ?Etablissement $site): VatRateResolution;
}
