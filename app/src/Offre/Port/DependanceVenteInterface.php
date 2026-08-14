<?php

declare(strict_types=1);

namespace App\Offre\Port;

use App\Offre\Entity\Produit;

/**
 * Port vers les modules Vente/Réservation (M2/M5) : indique si un produit a des ventes ou
 * dépendances actives (commande, abonnement, carte en cours). En L1, un stub renvoie
 * « aucune dépendance » ; M2/M5 le câbleront réellement (plan §7, T13).
 */
interface DependanceVenteInterface
{
    /** Vrai s'il existe au moins une vente/dépendance active référençant ce produit. */
    public function aDependanceActive(Produit $produit): bool;
}
