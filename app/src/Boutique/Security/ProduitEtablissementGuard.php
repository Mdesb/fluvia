<?php

declare(strict_types=1);

namespace App\Boutique\Security;

use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Cloisonnement établissement à l'achat en ligne (revue de sécurité — faille bloquante) : un produit
 * référencé dans un panier/une vente doit être rattaché à l'établissement du panier
 * (`Produit.etablissements`, cloisonnement M1/M3). Utilisé en **défense en profondeur** à la fois côté
 * ajout au panier (`AjouterLignePanierProcessor`, premier point de contrôle) et côté confirmation de
 * commande (`ConfirmerCommandeHandler::creerOuRecupererVente`, second point de contrôle juste avant
 * paiement) : sans ce garde, un produit d'un autre établissement (donc d'un autre exploitant/une autre
 * comptabilité) pouvait être acheté via le panier d'une vitrine tierce.
 */
final class ProduitEtablissementGuard
{
    public function verifier(Produit $produit, ?Etablissement $etablissement): void
    {
        if ($etablissement === null || !$this->appartient($produit, $etablissement)) {
            throw new UnprocessableEntityHttpException('Produit non rattaché à l\'établissement du panier (cloisonnement).');
        }
    }

    private function appartient(Produit $produit, Etablissement $etablissement): bool
    {
        foreach ($produit->getEtablissements() as $candidat) {
            if ($candidat->getId()->equals($etablissement->getId())) {
                return true;
            }
        }

        return false;
    }
}
