<?php

declare(strict_types=1);

namespace App\Offre\Service;

use App\Offre\Entity\Produit;
use App\Offre\Enum\StatutProduit;
use App\Offre\Port\DependanceVenteInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Gardes de transition du cycle de vie produit (RG-M1-09, décision « dépublier » US-L1-09).
 * Transitions autorisées :
 *  - brouillon → publié   : garde PublicationGuard (422 listant les prérequis manquants).
 *  - publié   → archivé   : toujours autorisé (produit non vendable, reste consultable).
 *  - publié   → brouillon : « dépublier », uniquement si aucune vente/dépendance active,
 *                           sinon 409 orientant vers « archiver ».
 *  - archivé  → brouillon : réactivation (remise en chantier).
 */
final class TransitionProduitHandler
{
    public function __construct(
        private readonly PublicationGuard $guard,
        private readonly DependanceVenteInterface $dependances,
    ) {
    }

    public function publier(Produit $produit): void
    {
        if ($produit->getStatut() !== StatutProduit::Brouillon) {
            throw new UnprocessableEntityHttpException(
                sprintf('Seul un produit en brouillon peut être publié (statut actuel : %s).', $produit->getStatut()->value)
            );
        }

        // Des phrases, pas des codes : le message est affiché tel quel à l'exploitant, et « canal,
        // prix, categorie_comptable » ne lui disait pas quoi faire. Les codes restent servis par
        // `GET /produits/{id}/readiness`.
        $manquants = $this->guard->missing($produit);
        if ($manquants !== []) {
            throw new UnprocessableEntityHttpException('Publication impossible. ' . implode(' ', $manquants));
        }

        $produit->setStatut(StatutProduit::Publie);
    }

    public function archiver(Produit $produit): void
    {
        if ($produit->getStatut() === StatutProduit::Archive) {
            return;
        }
        $produit->setStatut(StatutProduit::Archive);
    }

    public function depublier(Produit $produit): void
    {
        if ($produit->getStatut() !== StatutProduit::Publie) {
            throw new UnprocessableEntityHttpException(
                sprintf('Seul un produit publié peut être dépublié (statut actuel : %s).', $produit->getStatut()->value)
            );
        }

        if ($this->dependances->aDependanceActive($produit)) {
            throw new ConflictHttpException(
                'Dépublication impossible : le produit a des ventes/dépendances actives. Utilisez « archiver ».'
            );
        }

        $produit->setStatut(StatutProduit::Brouillon);
    }

    public function reactiver(Produit $produit): void
    {
        if ($produit->getStatut() !== StatutProduit::Archive) {
            throw new UnprocessableEntityHttpException(
                sprintf('Seul un produit archivé peut être réactivé (statut actuel : %s).', $produit->getStatut()->value)
            );
        }

        $produit->setStatut(StatutProduit::Brouillon);
    }
}
