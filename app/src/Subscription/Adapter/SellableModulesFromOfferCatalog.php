<?php

declare(strict_types=1);

namespace App\Subscription\Adapter;

use App\Subscription\Service\OfferCatalog;
use App\Website\Port\SellableModulesSource;

/**
 * Ce que le site public voit du catalogue de vente.
 *
 * **Une seule source de vérité, et c'est celle du tunnel.** On lit
 * {@see OfferCatalog::activeOptions()}, qui est le point de passage unique de la vente et de la
 * composition d'offre — et qui porte déjà la règle « un module qui ne peut rien servir ne se vend
 * pas » (§8.1, arbitrage du 04/09). Le site affiche donc exactement ce que le tunnel accepte de
 * vendre : les deux ne peuvent pas diverger, puisqu'ils lisent la même chose.
 *
 * ⚠ **AUCUN FILTRE SUPPLÉMENTAIRE ICI.** La tentation serait d'ajouter une règle d'affichage — ne
 * montrer que les options à prix non nul, écarter celles sans description… Chacune ferait de cet
 * adaptateur une seconde décision de vente, prise ailleurs que là où elle appartient, et le site
 * recommencerait à dire autre chose que le catalogue. Ce fichier traduit, il ne décide pas.
 */
final readonly class SellableModulesFromOfferCatalog implements SellableModulesSource
{
    public function __construct(private OfferCatalog $catalogue)
    {
    }

    public function sellableCapabilities(): array
    {
        // `activeOptions()` rend les options en vente INDEXÉES PAR CAPACITÉ : les clés sont
        // exactement les codes que le site cherche.
        return array_keys($this->catalogue->activeOptions());
    }
}
