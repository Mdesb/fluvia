<?php

declare(strict_types=1);

namespace App\Boutique\Service;

use App\Offre\Entity\Produit;
use App\Offre\Enum\Canal;
use App\Offre\Service\ResolveurPrix;

/**
 * UN PRODUIT DONT AUCUN TARIF NE SE RÉSOUT N'EST PAS VENDABLE EN LIGNE.
 *
 * ── POURQUOI CETTE RÈGLE VIT ICI, ET NULLE PART AILLEURS ────────────────────────────────────────
 *
 * Elle était écrite dans `CatalogueVitrineProvider`, qui cachait le produit. Mais **cacher n'est pas
 * refuser** : `AjouterLignePanierProcessor` résolvait le produit par sa référence sans consulter
 * aucun tarif, si bien qu'un produit invisible en vitrine restait ajoutable au panier par son
 * identifiant. Un lien, un intégrateur, un panier repris n'ont aucune raison de passer par la
 * vitrine.
 *
 * La recopier dans le processeur aurait donné deux implémentations d'une même règle. Deux
 * implémentations divergent : le jour où l'une apprend qu'un tarif de groupe compte, l'autre
 * l'ignore, et la boutique affiche un prix que le panier refuse — ou l'inverse, qui est pire.
 *
 * ── CE QUE « SE RÉSOUT » VEUT DIRE, ET CE QU'IL NE VEUT PAS DIRE ────────────────────────────────
 *
 * ⚠ **Un produit GRATUIT n'est pas un produit sans prix.** Un tarif à 0,00 se résout et reste
 * vendable — entrées libres, invitations, séances offertes. Confondre les deux retirerait de la
 * vente tout ce qui ne coûte rien, et le catalogue se viderait sans que personne comprenne pourquoi.
 *
 * On demande donc au résolveur de M1, canal en ligne, à l'instant présent : un tarif hors saison ou
 * hors période ne se résout pas, et c'est voulu — il n'est pas applicable aujourd'hui.
 *
 * ── D'OÙ VIENT LE DÉFAUT D'ORIGINE ──────────────────────────────────────────────────────────────
 *
 * `PublicationGuard` refuse de publier un produit sans prix valide (RG-M1-09), et il est bon. On ne
 * passait simplement pas par lui : une fixture posait `StatutProduit::Publie` en dur sur l'entité.
 * Un import, une reprise de données ou une migration produiraient le même état. D'où un contrôle à
 * l'ENTRÉE plutôt qu'une correction de la seule fixture : celle-ci referme le cas, celui-ci referme
 * la famille.
 */
final readonly class OnlineSellability
{
    public function __construct(
        private ResolveurPrix $resolveurPrix,
    ) {
    }

    /**
     * Les prix qui se résolvent aujourd'hui, en ligne, pour ce produit.
     *
     * @return list<float>
     */
    public function resolvedPrices(Produit $produit): array
    {
        $prix = [];
        $maintenant = new \DateTimeImmutable();

        foreach ($produit->getGrilles() as $grille) {
            $typeTarif = $grille->getTypeTarif();
            if ($typeTarif === null) {
                continue;
            }

            $resolu = $this->resolveurPrix->resoudre($produit, $typeTarif, $maintenant, Canal::EnLigne);
            if ($resolu !== null) {
                $prix[] = (float) $resolu;
            }
        }

        return $prix;
    }

    /** Vrai dès qu'un tarif se résout — y compris un tarif à 0,00. */
    public function isSellableOnline(Produit $produit): bool
    {
        return $this->resolvedPrices($produit) !== [];
    }
}
