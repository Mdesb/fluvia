<?php

declare(strict_types=1);

namespace App\Offre\Service;

use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;

/**
 * Résout les facettes/onglets visibles d'un produit selon son type (RG-M1-02 / CA-3) et purge
 * les saisies orphelines (une facette non visible ne conserve aucune donnée liée).
 */
final class ResolveurFacettes
{
    /**
     * Liste des facettes visibles pour un type donné.
     *
     * @return list<string>
     */
    public function facettesVisibles(?TypeProduit $type): array
    {
        return $type?->getFacettes() ?? [];
    }

    public function facetteVisible(?TypeProduit $type, string $facette): bool
    {
        return $type !== null && $type->aFacette($facette);
    }

    /**
     * Purge les facettes/entités liées qui ne correspondent pas au type courant (CA-3) :
     * une formule/carte/stock orphelin est détaché (puis supprimé par orphanRemoval).
     */
    public function purgerOrphelins(Produit $produit): void
    {
        $type = $produit->getType();

        if (!$this->facetteVisible($type, TypeProduit::FACETTE_FORMULE) && $produit->getFormule() !== null) {
            $produit->setFormule(null);
        }
        if (!$this->facetteVisible($type, TypeProduit::FACETTE_CARNET) && $produit->getCarte() !== null) {
            $produit->setCarte(null);
        }
        if (!$this->facetteVisible($type, TypeProduit::FACETTE_STOCK) && $produit->getStock() !== null) {
            $produit->setStock(null);
        }
    }
}
