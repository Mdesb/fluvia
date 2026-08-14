<?php

declare(strict_types=1);

namespace App\Offre\Service;

use App\Offre\Entity\Produit;

/**
 * Garde de publication (RG-M1-09 / CA-11) : un produit ne peut passer en « Publié » que s'il
 * possède un libellé, ≥1 site, ≥1 canal, ≥1 prix valide (non null) et une catégorie comptable.
 * Renvoie la liste PRÉCISE des prérequis manquants (vide = publiable).
 */
final class PublicationGuard
{
    /**
     * @return list<string> prérequis manquants (vide si publiable)
     */
    public function prerequisManquants(Produit $produit): array
    {
        $manquants = [];

        if ($produit->getLibelle() === []) {
            $manquants[] = 'libelle';
        }
        if ($produit->getEtablissements()->isEmpty()) {
            $manquants[] = 'site';
        }
        if ($produit->getCanaux() === []) {
            $manquants[] = 'canal';
        }
        if (!$produit->aPrixValide()) {
            $manquants[] = 'prix';
        }
        if (!$produit->aCategorieComptable()) {
            $manquants[] = 'categorie_comptable';
        }

        return $manquants;
    }

    public function estPubliable(Produit $produit): bool
    {
        return $this->prerequisManquants($produit) === [];
    }
}
