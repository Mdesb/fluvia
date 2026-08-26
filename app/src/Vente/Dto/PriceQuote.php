<?php

declare(strict_types=1);

namespace App\Vente\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Le prix qui sera facturé, **et la raison pour laquelle c'est celui-là**.
 *
 * `claude-H` a insisté sur le second point et elle a raison : *un prix sans sa raison ne se défend pas
 * devant un client qui trouve que c'est cher, et c'est exactement la conversation qu'on a au guichet.*
 * Rendre un montant seul aurait laissé le caissier annoncer un chiffre qu'il ne sait pas justifier.
 *
 * `motif` est une phrase lisible, pas un code : elle est destinée à être répétée à voix haute.
 */
final class PriceQuote
{
    /**
     * @param list<array{id: string, nom: string, type: string, valeur: string|null}> $promotions
     */
    public function __construct(
        #[Groups(['tarif:read'])]
        public readonly string $produit,
        #[Groups(['tarif:read'])]
        public readonly string $typeTarif,
        /** Nul quand le produit n'est pas commercialisé pour ce tarif à cette date — voir `motif`. */
        #[Groups(['tarif:read'])]
        public readonly ?string $prixUnitaire,
        #[Groups(['tarif:read'])]
        public readonly ?string $saison,
        #[Groups(['tarif:read'])]
        public readonly string $canal,
        #[Groups(['tarif:read'])]
        public readonly string $date,
        /** Les promotions qui s'appliqueraient automatiquement à la ligne (CA-4). */
        #[Groups(['tarif:read'])]
        public readonly array $promotions,
        #[Groups(['tarif:read'])]
        public readonly string $motif,
    ) {
    }
}
