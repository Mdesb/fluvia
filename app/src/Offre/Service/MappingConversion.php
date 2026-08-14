<?php

declare(strict_types=1);

namespace App\Offre\Service;

use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;

/**
 * Calcule le mapping d'une conversion de type assistée (RG-M1-11 / CA-13) : quelles facettes/
 * données sont conservées, ajoutées ou abandonnées entre l'ancien et le nouveau type. Sert à
 * l'affichage (confirmation) et à la journalisation (ConversionType).
 */
final class MappingConversion
{
    private const FACETTES = [
        TypeProduit::FACETTE_FORMULE,
        TypeProduit::FACETTE_CARNET,
        TypeProduit::FACETTE_STOCK,
        TypeProduit::FACETTE_BILLET,
        TypeProduit::FACETTE_CONSOMMATEUR,
        TypeProduit::FACETTE_VISIBILITE,
        TypeProduit::FACETTE_ACCES,
    ];

    /**
     * @return array{conserves: list<string>, ajoutes: list<string>, abandonnes: list<string>}
     */
    public function calculer(TypeProduit $ancien, TypeProduit $nouveau): array
    {
        $conserves = [];
        $ajoutes = [];
        $abandonnes = [];

        foreach (self::FACETTES as $facette) {
            $avant = $ancien->aFacette($facette);
            $apres = $nouveau->aFacette($facette);

            if ($avant && $apres) {
                $conserves[] = $facette;
            } elseif (!$avant && $apres) {
                $ajoutes[] = $facette;
            } elseif ($avant && !$apres) {
                $abandonnes[] = $facette;
            }
        }

        return ['conserves' => $conserves, 'ajoutes' => $ajoutes, 'abandonnes' => $abandonnes];
    }

    /**
     * Facettes/données concrètement peuplées sur le produit qui seront perdues par la conversion.
     *
     * @return list<string>
     */
    public function donneesPerdues(Produit $produit, TypeProduit $nouveau): array
    {
        $perdues = [];
        if ($produit->getFormule() !== null && !$nouveau->aFacette(TypeProduit::FACETTE_FORMULE)) {
            $perdues[] = 'formule';
        }
        if ($produit->getCarte() !== null && !$nouveau->aFacette(TypeProduit::FACETTE_CARNET)) {
            $perdues[] = 'carte';
        }
        if ($produit->getStock() !== null && !$nouveau->aFacette(TypeProduit::FACETTE_STOCK)) {
            $perdues[] = 'stock';
        }

        return $perdues;
    }
}
