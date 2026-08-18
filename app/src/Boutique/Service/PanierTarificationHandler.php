<?php

declare(strict_types=1);

namespace App\Boutique\Service;

use App\Boutique\Entity\LignePanierEnLigne;
use App\Boutique\Entity\PanierEnLigne;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Enum\Canal;
use App\Offre\Service\ResolveurPrix;
use App\Vente\Service\PanierCalculateur;

/**
 * Calcule le prix par ligne + le total d'un panier en ligne pour l'affichage (`GET
 * /boutique/paniers/{id}`) — comble des manques boutique révélés par le tunnel public. Réutilise
 * intégralement le moteur de tarification existant : `App\Offre\Service\ResolveurPrix` (M1, RG-M1-01/
 * 06/07) pour résoudre le prix public au canal `en_ligne`, et `App\Vente\Service\PanierCalculateur`
 * (M2) pour les conversions centimes/decimal — aucun calcul de prix recodé ici. Même heuristique que
 * `App\Boutique\Service\ConfirmerCommandeHandler::resoudrePrix()` (premier tarif commercialisé en
 * ligne) : le panier n'affiche pas de remise/promotion tant qu'il n'est pas transformé en Vente M2.
 */
final class PanierTarificationHandler
{
    public function __construct(
        private readonly ResolveurPrix $resolveurPrix,
        private readonly PanierCalculateur $calculateur,
    ) {
    }

    public function calculer(PanierEnLigne $panier): void
    {
        $totalCentimes = 0;
        foreach ($panier->getLignes() as $ligne) {
            \assert($ligne instanceof LignePanierEnLigne);
            $prix = $this->prixUnitaire($ligne);
            if ($prix === null) {
                $ligne->setPrixUnitaire(null)->setMontantLigne(null);
                continue;
            }

            $montantCentimes = $this->calculateur->centimes($prix) * $ligne->getQuantite();
            $ligne->setPrixUnitaire($prix)->setMontantLigne($this->calculateur->decimal($montantCentimes));
            $totalCentimes += $montantCentimes;
        }

        $panier->setTotal($this->calculateur->decimal($totalCentimes));
    }

    /** Premier prix résolu (canal `en_ligne`, date courante) parmi les grilles du produit. */
    private function prixUnitaire(LignePanierEnLigne $ligne): ?string
    {
        $produit = $ligne->getProduit();
        if ($produit === null) {
            return null;
        }

        $maintenant = new \DateTimeImmutable();
        foreach ($produit->getGrilles() as $grille) {
            \assert($grille instanceof GrilleTarifaire);
            $typeTarif = $grille->getTypeTarif();
            if ($typeTarif === null) {
                continue;
            }
            $resolu = $this->resolveurPrix->resoudre($produit, $typeTarif, $maintenant, Canal::EnLigne);
            if ($resolu !== null) {
                return $resolu;
            }
        }

        return null;
    }
}
