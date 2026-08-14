<?php

declare(strict_types=1);

namespace App\Offre\Port;

use App\Offre\Entity\Produit;

/**
 * Stub L1 du port DependanceVenteInterface : aucune dépendance de vente n'existe tant que M2/M5
 * ne sont pas implémentés. À remplacer par une implémentation réelle en lot L2.
 */
final class DependanceVenteStub implements DependanceVenteInterface
{
    public function aDependanceActive(Produit $produit): bool
    {
        return false;
    }
}
