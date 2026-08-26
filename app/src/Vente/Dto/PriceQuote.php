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
 *
 * **Les options viennent avec leur montant déjà calculé**, y compris pour un impact en pourcentage —
 * qui porte sur le prix de base résolu, que l'écran ne connaît pas. Sans cela, la caisse aurait
 * réimplémenté `ImpactOptionType` côté navigateur, et nous aurions eu une seconde règle tarifaire dans
 * une couche où personne ne voit la divergence.
 */
final class PriceQuote
{
    /**
     * @param list<array<string, mixed>> $promotions
     * @param list<array<string, mixed>> $options    groupes d'options, chacun avec ses valeurs
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
        /**
         * Options proposables pour ce produit, **les indisponibles comprises, avec leur raison**.
         *
         * `claude-H` a demandé qu'elles soient rendues plutôt qu'omises, en nuançant sa propre règle
         * (« une action qui n'a pas de sens est absente, jamais grisée ») : *la question n'est pas si
         * l'action est possible, c'est si l'utilisateur a une raison de la chercher.* Un client qui
         * réclame nommément une option que le caissier ne trouve pas l'envoie fouiller le paramétrage.
         * **Une absence sans explication est une énigme ; une présence expliquée est une réponse.**
         */
        #[Groups(['tarif:read'])]
        public readonly array $options = [],
        /** Quantité sur laquelle `totalLigne` est calculé. */
        #[Groups(['tarif:read'])]
        public readonly int $quantite = 1,
        /**
         * Prix de base **plus** les options retenues, pour **une** unité.
         *
         * Rendu par le serveur plutôt que laissé à l'addition de l'écran — et la raison n'est pas que
         * l'addition serait difficile. `claude-H` : *aujourd'hui c'est une addition, demain ce ne le
         * sera plus.* Un plafond sur le cumul d'options, une remise « pack », une option qui en rend
         * une autre gratuite : le serveur absorbe la règle sans qu'un écran change une ligne, là où une
         * addition côté navigateur deviendrait fausse **en continuant de rendre un nombre plausible**.
         */
        #[Groups(['tarif:read'])]
        public readonly ?string $totalUnitaire = null,
        /** `totalUnitaire` × `quantite`. Ferme l'ambiguïté de « unitaire par rapport à quoi ». */
        #[Groups(['tarif:read'])]
        public readonly ?string $totalLigne = null,
    ) {
    }
}
