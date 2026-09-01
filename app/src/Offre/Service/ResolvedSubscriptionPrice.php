<?php

declare(strict_types=1);

namespace App\Offre\Service;

use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeTarif;

/**
 * Ce que la résolution d'un abonnement vendable rend : de quoi écrire une ligne de vente.
 *
 * ⚠ `LigneVente` exige un produit ET un type de tarif, tous deux NON NULS — ce sont deux colonnes
 * `uuid` sans `nullable: true`. Rendre le prix seul aurait obligé chaque appelant à retrouver les
 * deux autres, c'est-à-dire à refaire la boucle de résolution : trois copies au lieu d'une.
 *
 * ⚠ `$prix` EST UNE CHAÎNE DÉCIMALE, comme la grille le stocke et comme `LigneVente` l'attend. Le
 * convertir en flottant ici ferait naître les erreurs de représentation exactement là où on
 * décide de ce qu'on prélève sur le compte d'un adhérent.
 */
final readonly class ResolvedSubscriptionPrice
{
    public function __construct(
        public Produit $product,
        public TypeTarif $tariffType,
        public string $price,
    ) {
    }

    /**
     * Le prix en centimes entiers, pour l'échéancier SEPA qui ne connaît que ça.
     *
     * ⚠ `round()` AVANT le cast, et pas de cast direct. Mesuré en PHP 8.4 sur cette machine :
     *
     *     "1.15"  ->  (int) (…* 100) = 114      (int) round(…* 100) = 115
     *     "0.29"  ->                     28                            29
     *     "39.90" ->                   3990                          3990   (celle-ci passe)
     *
     * Toutes les valeurs ne divergent pas — et c'est bien le problème : le défaut ne se voit pas
     * sur les prix ronds qu'on essaie d'abord, puis coûte un centime par échéance, douze fois par
     * an, sur tous les abonnés dont le tarif tombe mal.
     *
     * ⚠ J'avais d'abord écrit cet avertissement avec « 39.90 » comme exemple. Il est faux : cette
     * valeur-là passe. La règle est juste, l'exemple était inventé — vérifié avant de le laisser.
     */
    public function priceCents(): int
    {
        return (int) round(((float) $this->price) * 100);
    }
}
