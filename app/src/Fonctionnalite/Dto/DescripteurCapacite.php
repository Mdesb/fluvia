<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Dto;

/**
 * Fiche descriptive d'une capacité du catalogue (code, libellé, description, catégorie) — donnée de
 * référence, non persistée (cf. `App\Fonctionnalite\Service\CatalogueCapacites`).
 */
final class DescripteurCapacite
{
    public function __construct(
        public readonly string $code,
        public readonly string $libelle,
        public readonly string $description,
        public readonly string $categorie,
        /**
         * ⚠ CETTE CAPACITE EST-ELLE UNE VERTICALE D'ACTIVITE, PLUTOT QU'UN MODULE ACHETABLE ?
         *
         * « Padel, ce n'est pas un module » — Maxime, 01/09. Il a raison : `padel`, `piscine`,
         * `sport`, `patinoire` et `musee` sont des valeurs de l'enum `Metier`. Ce sont des PRESETS
         * — `PresetVerticale` fait correspondre `padel` a un jeu de cinq capacites — donc ce qu'un
         * etablissement EST, pas ce qu'il ajoute a la carte.
         *
         * Elles doivent rester au catalogue : sans elles, `Fonctionnalites::definir()` refuse le
         * code et le module vertical est present et definitivement inaccessible. Mais elles n'ont
         * rien a faire dans une boutique.
         *
         * ⚠ LE SERVEUR LE DIT, L'ECRAN NE LE DEVINE PAS. Filtrer cote frontal sur la categorie
         * « metier » reconstruirait une regle metier a partir d'une etiquette decorative, et les
         * deux divergeraient au premier ajout.
         */
        public readonly bool $estVerticale = false,
    ) {
    }
}
